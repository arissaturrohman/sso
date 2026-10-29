<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\SsoLogoutAudit;
use App\Models\User;
use App\Services\LogoutTokenBuilder;
use App\Services\SloService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Mengirim OIDC Back-Channel Logout ke sebuah Client App:
 * HTTP POST form dengan parameter logout_token (JWT RS256).
 *
 * Dijalankan via queue agar kegagalan/timeout satu SP tidak memblokir:
 *  - pencabutan token user di IdP,
 *  - pengiriman ke SP lain.
 *
 * Kebijakan retry:
 *  - 5xx / 408 / 425 / 429 / timeout / connection error -> retry (backoff).
 *  - 2xx -> delivered.
 *  - 4xx lain -> failed permanen (SP menolak token; retry tidak akan membantu).
 *  - Client di-revoke / URI berubah sebelum job jalan -> skipped.
 */
class SendBackChannelLogout implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120, 600, 1800];

    public int $timeout = 20;

    public function __construct(
        public string $userIdentifier,
        public string $clientId,
        public string $backchannelUri,
        public string $sid,
        public string $triggeredBy = 'idp',
        public ?string $jti = null
    ) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('sso.http.retry_until_minutes', 15));
    }

    public function handle(LogoutTokenBuilder $builder, SloService $slo): void
    {
        $this->jti ??= LogoutTokenBuilder::newJti();

        $client = Client::find($this->clientId);

        // Konfigurasi bisa berubah antara dispatch dan eksekusi. Jangan kirim
        // token ke URI lama / client yang sudah dicabut (mencegah SSRF &
        // kebocoran token ke endpoint yang tidak lagi tepercaya).
        if (! $client instanceof Client
            || $client->revoked
            || (string) $client->backchannel_logout_uri !== $this->backchannelUri) {
            $this->audit('skipped', null, 'Client revoked atau back-channel URI berubah.');

            return;
        }

        try {
            $slo->assertSafeBackchannelUri($this->backchannelUri);
        } catch (Throwable $e) {
            $this->audit('failed', null, 'URI ditolak: '.$e->getMessage());

            return;
        }

        $logoutToken = $builder->build(
            issuer: (string) config('sso.issuer'),
            audienceClientId: $this->clientId,
            userId: is_numeric($this->userIdentifier) ? (int) $this->userIdentifier : null,
            sid: $this->sid,
            jti: (string) $this->jti
        );

        try {
            $response = Http::connectTimeout((int) config('sso.http.connect_timeout', 3))
                ->timeout((int) config('sso.http.timeout', 5))
                ->withoutRedirecting()
                ->asForm()
                ->post($this->backchannelUri, [
                    'logout_token' => $logoutToken,
                ]);
        } catch (Throwable $e) {
            $this->audit('failed', null, 'Transport error: '.$e->getMessage());

            throw $e; // retry oleh queue worker
        }

        $status = $response->status();

        if ($response->successful()) {
            $this->audit('delivered', $status, substr($response->body(), 0, 500));

            return;
        }

        // 4xx permanen (kecuali 408/425/429 yang bersifat sementara).
        if ($status >= 400 && $status < 500 && ! in_array($status, [408, 425, 429], true)) {
            $this->audit('failed_permanent', $status, substr($response->body(), 0, 500));

            return;
        }

        $this->audit('failed', $status, substr($response->body(), 0, 500));

        throw new RuntimeException('Back-channel logout gagal dengan HTTP '.$status);
    }

    public function failed(?Throwable $e): void
    {
        $this->jti ??= LogoutTokenBuilder::newJti();

        $this->audit('failed_permanent', null, $e?->getMessage());

        Log::error('[SSO] Back-channel logout gagal permanen', [
            'client_id' => $this->clientId,
            'uri' => $this->backchannelUri,
            'user_id' => $this->userIdentifier,
            'jti' => $this->jti,
            'error' => $e?->getMessage(),
        ]);
    }

    protected function audit(string $status, ?int $httpStatus, ?string $responseBody): void
    {
        $userId = is_numeric($this->userIdentifier) ? (int) $this->userIdentifier : null;

        // User bisa sudah dihapus saat job berjalan (FK nullOnDelete).
        if ($userId !== null && ! User::whereKey($userId)->exists()) {
            $userId = null;
        }

        SsoLogoutAudit::updateOrCreate(
            ['logout_token_jti' => (string) $this->jti],
            [
                'user_id' => $userId,
                'client_id' => $this->clientId,
                'triggered_by' => $this->triggeredBy,
                'method' => 'back_channel',
                'status' => $status,
                'http_status' => $httpStatus,
                'response' => $responseBody,
            ]
        );
    }
}
