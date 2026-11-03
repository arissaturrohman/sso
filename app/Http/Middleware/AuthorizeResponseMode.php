<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dukungan `response_mode=form_post` (OIDC) untuk SEMUA client.
 *
 * Latar belakang: kode otorisasi Passport berukuran 900+ karakter, sehingga URL
 * callback client menjadi ±1 KB. Sebagian vhost client punya WAF/nginx yang
 * membatasi panjang request URI (mis. 330 karakter) dan membalas 403 sebelum
 * request sampai ke aplikasi. Dengan `response_mode=form_post`, parameter
 * `code`/`state` dikirim lewat body POST, bukan query string, sehingga URI
 * callback tetap pendek dan lolos batas tersebut.
 *
 * Middleware ini tidak mengubah perilaku default: client yang tidak mengirim
 * `response_mode` tetap menerima redirect 302 seperti biasa.
 *
 * Catatan implementasi:
 * - Dipasang di group `web` (butuh session).
 * - `response_mode` dari GET /oauth/authorize disimpan di session karena form
 *   consent (POST /oauth/authorize) tidak membawa parameter tersebut.
 */
class AuthorizeResponseMode
{
    private const SESSION_KEY = 'oauth_response_mode';

    private const ALLOWED_MODES = ['query', 'fragment', 'form_post'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('oauth/authorize')) {
            return $next($request);
        }

        $mode = $this->requestedMode($request);

        if ($request->isMethod('GET')) {
            if ($mode !== null) {
                $request->session()->put(self::SESSION_KEY, $mode);
            } else {
                // Jangan biarkan nilai lama terbawa ke request authorize lain.
                $request->session()->forget(self::SESSION_KEY);
            }
        }

        $response = $next($request);

        $mode ??= $request->session()->get(self::SESSION_KEY);

        if ($mode !== 'form_post' || $response->getStatusCode() !== 302) {
            return $response;
        }

        $location = (string) $response->headers->get('Location', '');

        if ($location === '') {
            return $response;
        }

        $parts = parse_url($location);

        if (! is_array($parts) || empty($parts['host'])) {
            return $response;
        }

        parse_str($parts['query'] ?? '', $params);

        // Hanya konversi respons otorisasi final (sukses atau error OAuth).
        // Redirect ke halaman login / halaman lain dibiarkan apa adanya.
        if (! isset($params['code']) && ! isset($params['error'])) {
            return $response;
        }

        $request->session()->forget(self::SESSION_KEY);

        // Beri tahu SecurityHeaders origin tujuan form agar CSP `form-action`
        // tidak memblokir submit (lihat app/Http/Middleware/SecurityHeaders.php).
        $request->attributes->set('sso.form_action_origin', $this->origin($parts));

        return $this->formPostResponse($parts, $params);
    }

    /**
     * Ambil response_mode yang valid dari query string / body.
     */
    private function requestedMode(Request $request): ?string
    {
        $mode = $request->input('response_mode');

        return is_string($mode) && in_array($mode, self::ALLOWED_MODES, true)
            ? $mode
            : null;
    }

    /**
     * Bangun origin (scheme://host[:port]) dari komponen URL.
     */
    private function origin(array $parts): string
    {
        return ($parts['scheme'] ?? 'https')
            .'://'
            .($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Halaman HTML yang otomatis mengirim parameter ke redirect_uri via POST.
     */
    private function formPostResponse(array $parts, array $params): Response
    {
        $action = $this->origin($parts).($parts['path'] ?? '/');

        $inputs = '';

        foreach ($params as $name => $value) {
            foreach ((array) $value as $item) {
                $inputs .= '<input type="hidden" name="'.e((string) $name).'" value="'.e((string) $item).'">';
            }
        }

        $html = '<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Mengalihkan ke aplikasi…</title>
<style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;color:#1f2937}p{opacity:.75}button{padding:.6rem 1.2rem;border-radius:.5rem;border:1px solid #d1d5db;background:#fff;cursor:pointer}</style>
</head>
<body onload="document.forms[0].submit()">
<form method="post" action="'.e($action).'">
'.$inputs.'
<noscript><p>JavaScript nonaktif. Klik tombol di bawah untuk melanjutkan.</p><button type="submit">Lanjutkan ke aplikasi</button></noscript>
</form>
<p>Mengalihkan ke aplikasi…</p>
</body>
</html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
