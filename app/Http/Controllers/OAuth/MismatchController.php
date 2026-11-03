<?php

namespace App\Http\Controllers\Oauth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman peringatan ketika user_type tidak cocok dengan client_type.
 *
 * User tetap bisa memilih "Lanjutkan" (simpan ke session agar tidak
 * di-warning lagi sesi ini) atau "Kembali" (batalkan otorisasi).
 */
class MismatchController extends Controller
{
    public function warning(Request $request): View
    {
        $clientName = $request->session()->get('mismatch.client_name');
        $clientType = $request->session()->get('mismatch.client_type');
        $userType = $request->session()->get('mismatch.user_type');

        if (! $clientName || ! $clientType || ! $userType) {
            // Data tidak lengkap, redirect ke home
            return redirect()->route('home');
        }

        $typeLabels = [
            'opd' => 'OPD',
            'pbj' => 'Staf PBJ',
        ];

        return view('oauth.mismatch-warning', [
            'clientName' => $clientName,
            'clientTypeLabel' => $typeLabels[$clientType] ?? $clientType,
            'userTypeLabel' => $typeLabels[$userType] ?? $userType,
        ]);
    }

    public function continue(Request $request): RedirectResponse
    {
        $authToken = $request->session()->pull('mismatch.auth_token');
        $clientId = $request->session()->pull('mismatch.client_id');

        // Tandai user sudah mengakui mismatch untuk sesi ini
        $request->session()->put('mismatch.acknowledged', true);

        if (! $authToken || ! $clientId) {
            return redirect()->route('home');
        }

        // Redirect kembali ke authorize dengan auth_token
        return redirect()->route('passport.authorizations.authorize', [
            'auth_token' => $authToken,
        ]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        // Hapus data mismatch dari session
        $request->session()->forget([
            'mismatch.auth_token',
            'mismatch.client_id',
            'mismatch.client_name',
            'mismatch.client_type',
            'mismatch.user_type',
        ]);

        // Redirect ke halaman post-logout atau home
        return redirect()->route('home')->with('status', 'Otorisasi dibatalkan.');
    }
}