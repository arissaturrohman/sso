<?php

namespace App\Http\Controllers;

use App\Models\SsoSession;
use App\Services\SloService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman "Perangkat & Sesi Aktif": user dapat melihat aplikasi/perangkat
 * yang terhubung akunnya dan memutus sesi tertentu secara mandiri.
 */
class SessionsController extends Controller
{
    public function __construct(private readonly SloService $slo) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $sessions = SsoSession::where('user_id', $user->id)
            ->with('client')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (SsoSession $session) {
                $client = $session->client;

                return (object) [
                    'id' => $session->id,
                    'is_idp' => $client === null,
                    'client_name' => $client?->name ?? 'Sesi IdP (perangkat ini)',
                    'ip_address' => $session->ip_address,
                    'device_name' => $session->device_name,
                    'user_agent' => $session->user_agent,
                    'last_activity' => $session->last_activity_at,
                    'expires_at' => $session->expires_at,
                    'active' => $session->logged_out_at === null && $session->revoked_at === null,
                    'logged_out_at' => $session->logged_out_at,
                ];
            });

        return view('sessions.index', ['sessions' => $sessions]);
    }

    /**
     * Putus satu sesi tertentu (khusus sesi client).
     *
     * Kepemilikan diperiksa eksplisit (defense-in-depth di samping route
     * model binding UUID). 404 — bukan 403 — agar keberadaan ID milik user
     * lain tidak bisa dikonfirmasi.
     */
    public function destroy(Request $request, SsoSession $session): RedirectResponse
    {
        $user = $request->user();

        abort_unless((int) $session->user_id === (int) $user->id, 404);

        if (empty($session->client_id)) {
            return back()->with('status', 'Sesi IdP dikelola oleh browser ini dan tidak dapat diputus dari halaman ini.');
        }

        if ($session->logged_out_at !== null || $session->revoked_at !== null) {
            return back()->with('status', 'Sesi tersebut sudah berakhir.');
        }

        $this->slo->terminateSession($user, $session, 'idp');

        return back()->with('status', 'Sesi berhasil diputus.');
    }
}
