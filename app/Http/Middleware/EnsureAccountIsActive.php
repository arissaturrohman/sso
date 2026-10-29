<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Token;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menegakkan status akun dan masa berlaku token pada setiap request.
 *
 * Ini menutup dua celah:
 *  - User yang di-suspend/dinonaktifkan admin tidak bisa terus memakai
 *    access token yang belum kedaluwarsa (default 15 menit).
 *  - Token yang diterbitkan sebelum global logout (tokens_valid_after)
 *    langsung ditolak, termasuk bila Passport sempat menerbitkannya lewat
 *    race refresh-vs-logout.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (method_exists($user, 'isActive') && ! $user->isActive()) {
            return $this->deny($request, 'Akun Anda tidak aktif.');
        }

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $epoch = $user->tokens_valid_after ?? null;

        if ($token instanceof Token && $epoch !== null && $token->created_at !== null
            && $token->created_at->lessThanOrEqualTo($epoch)) {
            $token->forceFill(['revoked' => true])->save();

            return $this->deny($request, 'Token sudah tidak berlaku. Silakan login kembali.');
        }

        return $next($request);
    }

    protected function deny(Request $request, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*') || $request->is('oauth/*')) {
            return response()
                ->json(['error' => 'invalid_token', 'error_description' => $message], 401)
                ->header('WWW-Authenticate', 'Bearer error="invalid_token"')
                ->header('Cache-Control', 'no-store');
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()
            ->route('login')
            ->withErrors(['identifier' => 'Sesi Anda telah berakhir. Silakan login kembali.']);
    }
}
