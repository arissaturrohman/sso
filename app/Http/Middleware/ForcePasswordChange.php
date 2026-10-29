<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selama user belum mengganti password (must_change_password = true),
 * semua halaman dialihkan ke form ubah password.
 *
 * Dikecualikan: halaman ubah password itu sendiri dan logout.
 */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        // Halaman yang tetap boleh diakses
        if ($request->routeIs('password.change', 'password.change.update', 'logout', 'sso.logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Anda harus mengubah password terlebih dahulu.',
            ], 403);
        }

        // Simpan tujuan awal (mis. URL /oauth/authorize) agar setelah ganti
        // password user dikembalikan ke sana — jangan timpa intended yang ada.
        if ($request->isMethod('GET') && ! $request->session()->has('url.intended')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('password.change')->with(
            'warning',
            'Demi keamanan, Anda wajib mengganti password terlebih dahulu.'
        );
    }
}
