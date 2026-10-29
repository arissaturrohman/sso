<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Origin aplikasi client yang boleh menjadi tujuan akhir submit form
        // login (mis. http://localhost:8001). Diambil dari konfigurasi.
        $allowedFormOrigins = collect(config('sso.form_action_origins', []))
            ->filter()
            ->map(fn ($origin) => rtrim(trim((string) $origin), '/'))
            ->unique()
            ->implode(' ');

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "connect-src 'self'",
            // Chrome menerapkan form-action pada SELURUH rantai redirect hasil
            // submit form, sehingga origin aplikasi client (yang menjadi tujuan
            // akhir redirect) harus ikut diizinkan. Tanpa ini, login SSO diblokir.
            "form-action 'self'".($allowedFormOrigins !== '' ? ' '.$allowedFormOrigins : ''),
            "frame-ancestors 'none'",
            "base-uri 'self'",
        ]));

        // HSTS hanya saat HTTPS aktif
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }
}
