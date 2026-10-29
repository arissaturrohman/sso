<?php

namespace App\Http\Middleware;

use App\Services\SsoSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackAuthorizeRequest
{
    /**
     * Merekam pemetaan sesi IdP <-> client app ketika user ter-autentikasi
     * dan mulai proses /oauth/authorize. Ini memungkinkan SLO nantinya
     * mengetahui client apps mana yang aktif dalam sesi IdP.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        $clientId = $request->query('client_id');

        // Sesuaikan hanya di GET /oauth/authorize
        if ($request->is('oauth/authorize') && $request->isMethod('GET') && $clientId) {
            if ($user) {
                try {
                    SsoSessionService::trackClientAuthorization($user, $clientId);
                } catch (\Throwable $e) {
                    \Log::warning('[SSO] Gagal tracking authorize: '.$e->getMessage());
                }
            } else {
                // User belum login: simpan URL authorize agar kembali ke sini
                // setelah login berhasil (redirect()->intended()).
                session()->put('url.intended', $request->fullUrl());
            }
        }

        return $next($request);
    }
}
