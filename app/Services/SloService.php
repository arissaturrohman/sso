<?php

namespace App\Services;

use App\Jobs\SendBackChannelLogout;
use App\Models\Client;
use App\Models\SsoLogoutAudit;
use App\Models\SsoSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Otak dari Single Logout (SLO).
 *
 * Kontrak keamanan:
 *  - Semua token (access + refresh) user dicabut dalam transaksi.
 *  - `users.tokens_valid_after` menjadi epoch: token yang diterbitkan sebelum
 *    logout tidak boleh dipakai lagi (lihat EnsureAccountIsActive + listener
 *    AccessTokenCreated untuk menutup race refresh vs logout).
 *  - Back-channel dikirim PER (sid, client), bukan satu sid untuk semua client.
 *  - Setiap pengiriman punya `jti` unik dan baris audit sendiri.
 *  - Kegagalan satu SP tidak membatalkan pencabutan token atau pengiriman ke SP lain.
 */
class SloService
{
    /**
     * Global logout untuk seorang user.
     *
     * @return array{clients: Collection<int, Client>, sid: ?string, started_at: Carbon}
     */
    public function logout(User $user, ?string $sid = null, string $triggeredBy = 'idp'): array
    {
        $startedAt = now();
        $lock = Cache::lock('slo:logout:user:'.$user->id, 15);
        $locked = false;

        try {
            $locked = $lock->get();
        } catch (Throwable $e) {
            Log::warning('[SSO] Gagal mengambil lock logout', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $locked) {
            // Jangan blokir user hanya karena lock. Operasi di bawah idempoten;
            // duplikasi back-channel diterima dan aman (jti selalu baru).
            Log::warning('[SSO] Lock logout tidak didapat, lanjut secara idempoten', [
                'user_id' => $user->id,
            ]);
        }

        try {
            // Ambil sesi client SEBELUM ditandai berakhir. Satu baris = satu
            // kombinasi (sesi IdP, client); sid untuk back-channel harus
            // session_hash baris tersebut, bukan session browser saat ini.
            $sessions = SsoSession::query()
                ->where('user_id', $user->id)
                ->whereNotNull('client_id')
                ->whereNull('logged_out_at')
                ->whereNull('revoked_at')
                ->where(function ($query) use ($startedAt) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $startedAt);
                })
                ->with('client')
                ->get();

            DB::transaction(function () use ($user, $startedAt) {
                // Serialisasi dengan perubahan status/role oleh admin.
                DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

                DB::table('users')->where('id', $user->id)->update([
                    'tokens_valid_after' => $startedAt,
                    'updated_at' => $startedAt,
                ]);

                $this->revokeAllTokensForUser($user->id);

                SsoSession::query()
                    ->where('user_id', $user->id)
                    ->whereNull('logged_out_at')
                    ->update([
                        'logged_out_at' => $startedAt,
                        'revoked_at' => $startedAt,
                        'updated_at' => $startedAt,
                    ]);
            });

            // Sapuan kedua: menangkap token yang di-insert saat transaksi berjalan.
            $this->revokeAllTokensForUser($user->id);

            $clients = $sessions->map->client->filter()->unique('id')->values();

            // Anti duplikasi (session_hash + client_id). Kirim satu job per sid.
            $sessions->unique(fn (SsoSession $session) => $session->session_hash.'|'.$session->client_id)
                ->each(function (SsoSession $session) use ($user, $sid, $triggeredBy) {
                    $client = $session->client;

                    if (! $client instanceof Client || $client->revoked || empty($client->backchannel_logout_uri)) {
                        return;
                    }

                    $this->dispatchBackChannel(
                        userId: (int) $user->getAuthIdentifier(),
                        clientId: (string) $client->id,
                        uri: (string) $client->backchannel_logout_uri,
                        sid: (string) ($session->session_hash ?: $sid ?: ''),
                        triggeredBy: $triggeredBy,
                    );
                });

            return [
                'clients' => $clients,
                'sid' => $sid,
                'started_at' => $startedAt,
            ];
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
    }

    /**
     * Putus satu sesi client milik user (dari halaman /sessions).
     */
    public function terminateSession(User $user, SsoSession $session, string $triggeredBy = 'idp'): void
    {
        if ($session->user_id !== $user->id) {
            throw new \InvalidArgumentException('Sesi bukan milik user.');
        }

        if (empty($session->client_id)) {
            return;
        }

        DB::transaction(function () use ($user, $session) {
            $tokenIds = DB::table('oauth_access_tokens')
                ->where('user_id', $user->id)
                ->where('client_id', $session->client_id)
                ->pluck('id');

            if ($tokenIds->isNotEmpty()) {
                DB::table('oauth_access_tokens')
                    ->whereIn('id', $tokenIds)
                    ->where('revoked', false)
                    ->update(['revoked' => true, 'updated_at' => now()]);

                DB::table('oauth_refresh_tokens')
                    ->whereIn('access_token_id', $tokenIds)
                    ->where('revoked', false)
                    ->update(['revoked' => true, 'expires_at' => now()]);
            }

            $session->forceFill([
                'logged_out_at' => now(),
                'revoked_at' => now(),
            ])->save();
        });

        $client = Client::find($session->client_id);

        if ($client instanceof Client && ! $client->revoked && filled($client->backchannel_logout_uri)) {
            $this->dispatchBackChannel(
                userId: (int) $user->getAuthIdentifier(),
                clientId: (string) $client->id,
                uri: (string) $client->backchannel_logout_uri,
                sid: (string) $session->session_hash,
                triggeredBy: $triggeredBy,
            );
        }
    }

    /**
     * Validasi post_logout_redirect_uri + tambahkan state.
     *
     * Tanpa client_id: hanya origin IdP sendiri yang boleh (mencegah open redirect).
     * Dengan client_id: harus exact-match salah satu redirect_uris client aktif.
     */
    public function resolvePostLogoutRedirect(?string $clientId, ?string $uri, ?string $state = null): string
    {
        $default = route('login');

        if (! filled($uri)) {
            return $this->appendState($default, $state);
        }

        $uri = trim((string) $uri);

        if (strlen($uri) > 2048) {
            $this->rejectPostLogout('URI terlalu panjang.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $uri) || str_starts_with($uri, '//') || str_starts_with($uri, '/\\')) {
            $this->rejectPostLogout('URI mengandung karakter terlarang.');
        }

        $parts = parse_url($uri);

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            $this->rejectPostLogout('URI tidak valid.');
        }

        $host = $parts['host'] ?? null;

        // URL relatif (path saja) aman selama diawali satu slash.
        if ($host === null) {
            if (! str_starts_with($uri, '/')) {
                $this->rejectPostLogout('URI relatif tidak valid.');
            }

            return $this->appendState($uri, $state);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            $this->rejectPostLogout('Skema URI tidak diizinkan.');
        }

        if ($this->httpsRequired() && $scheme !== 'https' && ! $this->isLoopbackHost($host)) {
            $this->rejectPostLogout('HTTPS wajib digunakan.');
        }

        if (filled($clientId)) {
            $client = Client::query()
                ->where('id', $clientId)
                ->where('revoked', false)
                ->first();

            if (! $client) {
                $this->rejectPostLogout('Client tidak dikenal.');
            }

            $allowed = array_map('strval', (array) $client->redirect_uris);

            if (! in_array($uri, $allowed, true)) {
                $this->rejectPostLogout('URI tidak terdaftar untuk client.');
            }

            return $this->appendState($uri, $state);
        }

        // Tanpa client_id: hanya same-origin dengan IdP.
        $app = parse_url((string) config('app.url'));

        if (! is_array($app) || empty($app['host'])) {
            $this->rejectPostLogout('Konfigurasi APP_URL tidak valid.');
        }

        $appScheme = strtolower((string) ($app['scheme'] ?? 'https'));
        $appPort = (int) ($app['port'] ?? ($appScheme === 'https' ? 443 : 80));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if ($scheme !== $appScheme
            || strcasecmp($host, (string) $app['host']) !== 0
            || $port !== $appPort) {
            $this->rejectPostLogout('Redirect ke origin lain diblokir.');
        }

        return $this->appendState($uri, $state);
    }

    /**
     * Cegah SSRF: hanya http(s), host publik, tanpa userinfo/fragment.
     * Loopback diizinkan hanya pada environment local/testing.
     */
    public function assertSafeBackchannelUri(string $uri): void
    {
        $parts = parse_url($uri);

        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            throw new \InvalidArgumentException('Back-channel URI tidak valid.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Back-channel URI mengandung komponen terlarang.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = (string) $parts['host'];

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Back-channel URI harus http/https.');
        }

        if ($scheme !== 'https' && ! $this->isLoopbackHost($host)) {
            throw new \InvalidArgumentException('Back-channel URI wajib HTTPS.');
        }

        $ips = $this->resolveHost($host);

        if ($ips === []) {
            throw new \InvalidArgumentException('Host back-channel tidak dapat di-resolve.');
        }

        foreach ($ips as $ip) {
            if ($this->isAllowedIp($ip)) {
                continue;
            }

            throw new \InvalidArgumentException('Back-channel URI mengarah ke alamat privat/reserved.');
        }
    }

    /**
     * Dispatch satu back-channel logout + catat audit `queued`.
     */
    public function dispatchBackChannel(
        int $userId,
        string $clientId,
        string $uri,
        string $sid,
        string $triggeredBy
    ): void {
        $jti = LogoutTokenBuilder::newJti();

        try {
            $this->assertSafeBackchannelUri($uri);
        } catch (Throwable $e) {
            Log::warning('[SSO] Back-channel URI ditolak', [
                'client_id' => $clientId,
                'uri' => $uri,
                'error' => $e->getMessage(),
            ]);

            SsoLogoutAudit::create([
                'user_id' => $userId,
                'client_id' => $clientId,
                'triggered_by' => $triggeredBy,
                'method' => 'back_channel',
                'logout_token_jti' => $jti,
                'status' => 'failed',
                'http_status' => null,
                'response' => 'URI ditolak: '.$e->getMessage(),
            ]);

            return;
        }

        SsoLogoutAudit::create([
            'user_id' => $userId,
            'client_id' => $clientId,
            'triggered_by' => $triggeredBy,
            'method' => 'back_channel',
            'logout_token_jti' => $jti,
            'status' => 'queued',
            'http_status' => null,
            'response' => null,
        ]);

        try {
            // Jangan pakai afterResponse(): itu memanggil dispatchSync() dan
            // melewati queue sehingga retry/backoff tidak berjalan. Dispatch
            // normal agar queue worker yang menangani (butuh QUEUE_CONNECTION
            // != sync + worker aktif).
            SendBackChannelLogout::dispatch(
                userIdentifier: (string) $userId,
                clientId: $clientId,
                backchannelUri: $uri,
                sid: $sid,
                triggeredBy: $triggeredBy,
                jti: $jti,
            )->onQueue((string) config('sso.queue', 'slo'));
        } catch (Throwable $e) {
            Log::error('[SSO] Gagal memasukkan job back-channel ke queue', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            SsoLogoutAudit::where('logout_token_jti', $jti)->update([
                'status' => 'failed',
                'response' => 'Queue error: '.$e->getMessage(),
            ]);
        }
    }

    protected function revokeAllTokensForUser(int $userId): void
    {
        $now = now();

        DB::table('oauth_access_tokens')
            ->where('user_id', $userId)
            ->where('revoked', false)
            ->update(['revoked' => true, 'updated_at' => $now]);

        // Subquery: mencakup token yang baru saja dibuat/di-refresh.
        DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', function ($query) use ($userId) {
                $query->select('id')
                    ->from('oauth_access_tokens')
                    ->where('user_id', $userId);
            })
            ->where('revoked', false)
            ->update(['revoked' => true, 'expires_at' => $now]);
    }

    protected function appendState(string $uri, ?string $state): string
    {
        if ($state === null || $state === '') {
            return $uri;
        }

        $max = (int) config('sso.post_logout.state_max_length', 512);

        if (strlen($state) > $max || preg_match('/[^\x20-\x7E]/', $state)) {
            $this->rejectPostLogout('Parameter state tidak valid.');
        }

        $fragment = '';

        if (($pos = strpos($uri, '#')) !== false) {
            $fragment = substr($uri, $pos);
            $uri = substr($uri, 0, $pos);
        }

        $query = [];

        if (($pos = strpos($uri, '?')) !== false) {
            parse_str(substr($uri, $pos + 1), $query);
            $uri = substr($uri, 0, $pos);
        }

        $query['state'] = $state;

        return $uri.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986).$fragment;
    }

    protected function rejectPostLogout(string $reason): never
    {
        Log::notice('[SSO] post_logout_redirect_uri ditolak', [
            'reason' => $reason,
            'ip' => request()->ip(),
        ]);

        // Pesan generik: jangan bocorkan apakah client/URI terdaftar.
        abort(400, 'Permintaan logout tidak valid.');
    }

    protected function httpsRequired(): bool
    {
        $configured = config('sso.post_logout.require_https');

        if ($configured !== null) {
            return (bool) $configured;
        }

        return app()->isProduction();
    }

    protected function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_starts_with($host, '127.');
    }

    /**
     * @return list<string>
     */
    protected function resolveHost(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        if ($this->isLoopbackHost($host)) {
            return ['127.0.0.1'];
        }

        $cacheKey = 'sso:dns:'.sha1($host);

        return Cache::remember($cacheKey, 300, function () use ($host) {
            $ips = [];

            $records = @dns_get_record($host, DNS_A | DNS_AAAA);

            foreach ($records ?: [] as $record) {
                if (! empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }

            if ($ips === []) {
                $resolved = @gethostbynamel($host);

                if (is_array($resolved)) {
                    $ips = $resolved;
                }
            }

            return array_values(array_unique($ips));
        });
    }

    protected function isAllowedIp(string $ip): bool
    {
        if ($this->isLoopbackHost($ip) && app()->environment(['local', 'testing'])) {
            return true;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
