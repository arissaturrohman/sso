<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\ClientRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cek kecocokan jenis user (OPD/PBJ) dengan jenis aplikasi klien.
 *
 * Jika tidak cocok, redirect ke halaman peringatan sebelum user bisa
 * melanjutkan otorisasi. User tetap bisa memilih "Lanjutkan" untuk
 * mengabaikan peringatan (soft block), atau "Kembali" untuk membatalkan.
 */
class CheckClientUserTypeMatch
{
    public function __construct(
        private readonly ClientRepository $clients
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Hanya berlaku untuk GET /oauth/authorize (halaman consent)
        if (! $request->is('oauth/authorize') || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $user = Auth::user();

        // User belum login — biarkan Passport handle redirect ke login
        if (! $user) {
            return $next($request);
        }

        // User tidak punya user_type — lewati cek (opsional)
        if (! $user->user_type) {
            return $next($request);
        }

        // User sudah acknowledge mismatch di sesi ini — lewati cek
        if ($request->session()->get('mismatch.acknowledged')) {
            return $next($request);
        }

        $clientId = $request->query('client_id');

        if (! $clientId) {
            return $next($request);
        }

        $client = $this->clients->find($clientId);

        // Client tidak ditemukan atau tidak punya client_type — lewati
        if (! $client || ! $client->client_type) {
            return $next($request);
        }

        // Logika hierarki:
        // - App OPD (client_type='opd') → boleh diakses OPD & PBJ
        // - App PBJ (client_type='pbj') → hanya boleh diakses PBJ
        $allow = match ($client->client_type) {
            'opd' => in_array($user->user_type, ['opd', 'pbj']), // OPD app: both can access
            'pbj' => $user->user_type === 'pbj',                   // PBJ app: only PBJ
            default => true,                                       // unknown type: allow
        };

        if (! $allow) {
            // Simpan auth_token ke session agar bisa lanjut setelah warning
            $authToken = $request->query('auth_token');
            if ($authToken) {
                $request->session()->put('mismatch.auth_token', $authToken);
            }

            // Simpan client_id untuk ditampilkan di halaman warning
            $request->session()->put('mismatch.client_id', $clientId);
            $request->session()->put('mismatch.client_name', $client->name);
            $request->session()->put('mismatch.client_type', $client->client_type);
            $request->session()->put('mismatch.user_type', $user->user_type);

            return redirect()->route('oauth.mismatch-warning');
        }

        return $next($request);
    }
}