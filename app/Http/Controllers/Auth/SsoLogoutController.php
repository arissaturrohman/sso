<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SloService;
use App\Services\SsoSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Single Logout (SLO), dua pemicu:
 *
 * 1. POST /logout  — tombol logout di dashboard IdP (CSRF-protected).
 * 2. GET  /sso/logout — RP-initiated logout dari Client App.
 *
 * Semua logika pencabutan token, penandaan sesi, validasi redirect, dan
 * dispatch back-channel ada di SloService. Controller hanya menangani
 * HTTP/session.
 */
class SsoLogoutController extends Controller
{
    public function __construct(private readonly SloService $slo) {}

    /**
     * RP-initiated logout (GET). Tidak memerlukan autentikasi: bila user
     * sudah tidak punya sesi, cukup redirect ke post_logout_redirect_uri
     * yang tervalidasi (atau halaman login).
     */
    public function redirectLogout(Request $request): RedirectResponse
    {
        $target = $this->slo->resolvePostLogoutRedirect(
            $this->stringInput($request, 'client_id'),
            $this->stringInput($request, 'post_logout_redirect_uri'),
            $this->stringInput($request, 'state'),
        );

        $user = Auth::user();

        if (! $user) {
            return $this->noStore(redirect()->to($target));
        }

        $this->slo->logout($user, SsoSessionService::sessionHash(), 'client_app');
        $this->destroyIdpSession($request);

        return $this->noStore(
            redirect()->to($target)->with('status', 'Anda telah logout dari semua aplikasi.')
        );
    }

    /**
     * Logout dari dashboard IdP (POST, auth + CSRF).
     */
    public function webLogout(Request $request): RedirectResponse
    {
        // Sebelumnya parameter ini diteruskan mentah-mentah -> open redirect.
        $target = $this->slo->resolvePostLogoutRedirect(
            $this->stringInput($request, 'client_id'),
            $this->stringInput($request, 'post_logout_redirect_uri'),
        );

        $user = $request->user();

        if ($user) {
            $this->slo->logout($user, SsoSessionService::sessionHash(), 'idp');
            $this->destroyIdpSession($request);
        }

        return $this->noStore(
            redirect()->to($target)->with('status', 'Anda telah logout dari semua aplikasi.')
        );
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function destroyIdpSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function noStore(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
