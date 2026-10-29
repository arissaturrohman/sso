<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforcePkce
{
    /**
     * PKCE (S256) wajib untuk authorization code grant — meskipun client
     * bersifat confidential. Mencegah authorization code interception.
     *
     * Dipasang global; hanya aktif pada GET /oauth/authorize.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isAuthorizeEndpoint = $request->is('oauth/authorize')
            && $request->isMethod('GET')
            && $request->query('response_type') === 'code';

        if (! $isAuthorizeEndpoint) {
            return $next($request);
        }
        if ($request->query('response_type') === 'token') {
            // implicit grant ternyata tidak dinonaktifkan di sini — dilarang.
            abort(400, 'Implicit grant dinonaktifkan. Gunakan authorization code + PKCE.');
        }

        $challenge = $request->query('code_challenge');
        $method = $request->query('code_challenge_method');

        if (empty($challenge) || empty($method)) {
            abort(400, 'Parameter code_challenge dan code_challenge_method wajib ada (PKCE wajib).');
        }

        if ($method !== 'S256') {
            abort(400, 'Hanya code_challenge_method=S256 yang didukung.');
        }

        if (strlen($challenge) < 43 || strlen($challenge) > 128) {
            abort(400, 'code_challenge tidak valid (panjang 43-128 karakter).');
        }

        return $next($request);
    }
}
