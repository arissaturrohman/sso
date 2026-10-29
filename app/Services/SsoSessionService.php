<?php

namespace App\Services;

use App\Models\SsoSession;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

class SsoSessionService
{
    /**
     * Hash dari session id saat ini (jadi "sid" / identitas sesi IdP).
     * Session id mentah tidak pernah disimpan.
     */
    public static function sessionHash(): ?string
    {
        $sessionId = session()->getId();

        return $sessionId ? hash('sha256', $sessionId) : null;
    }

    /**
     * Catat/refresh sesi IdP murni (tanpa client) setelah login berhasil.
     */
    public static function trackIdpLogin(User $user): ?SsoSession
    {
        return self::upsertSession($user, null);
    }

    /**
     * Catat/refresh pemetaan (sesi IdP <-> client app). Dipanggil ketika
     * user me-approve otorisasi OAuth untuk sebuah client.
     */
    public static function trackClientAuthorization(User $user, string $clientId): ?SsoSession
    {
        return self::upsertSession($user, $clientId);
    }

    /**
     * Tandai sesi sebagai berakhir (logout global / per perangkat).
     */
    public static function markLoggedOut(SsoSession $session): void
    {
        if (is_null($session->logged_out_at)) {
            $session->logged_out_at = Date::now();
            $session->revoked_at = Date::now();
            $session->save();
        }
    }

    /**
     * Bersihkan baris sesi yang kedaluwarsa (housekeeping).
     */
    public static function purgeExpired(): int
    {
        return SsoSession::where(function ($query) {
            $query->where('expires_at', '<', now()->subDay())
                ->orWhere(function ($query) {
                    $query->whereNull('expires_at')
                        ->where('updated_at', '<', now()->subDays(7));
                });
        })->delete();
    }

    protected static function upsertSession(User $user, ?string $clientId): ?SsoSession
    {
        $hash = self::sessionHash();

        if (! $hash) {
            return null;
        }

        $session = SsoSession::query()
            ->where('session_hash', $hash)
            ->where('client_id', $clientId)
            ->first() ?? new SsoSession;

        // forceFill: user_id/revoked_at/logged_out_at TIDAK boleh mass-assignable.
        $session->forceFill([
            'session_hash' => $hash,
            'client_id' => $clientId,
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
            'device_name' => self::deviceNameFromAgent((string) request()->userAgent()),
            'last_activity_at' => Date::now(),
            'expires_at' => now()->addMinutes((int) config('session.lifetime', 120)),
            'logged_out_at' => null,
            'revoked_at' => null,
        ]);

        $session->save();

        return $session;
    }

    protected static function deviceNameFromAgent(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Safari') => 'Safari',
            str_contains($agent, 'curl') => 'curl/API',
            default => 'Perangkat tidak dikenal',
        };
    }

    /**
     * Group key untuk logout: sid + sub.
     */
    public static function makeSid(User $user): string
    {
        return self::sessionHash() ?? hash('sha256', $user->id.Str::random(16));
    }
}
