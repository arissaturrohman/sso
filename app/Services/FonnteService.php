<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pengiriman pesan WhatsApp melalui gateway Fonnte (https://fonnte.com).
 *
 * Konfigurasi (.env):
 *   FONNTE_TOKEN=xxxxx
 *   FONNTE_API_URL=https://api.fonnte.com/send   (opsional)
 *   FONNTE_TIMEOUT=15                            (opsional, detik)
 *   FONNTE_COUNTRY_CODE=62                       (opsional)
 */
class FonnteService
{
    public function isConfigured(): bool
    {
        return filled(config('services.fonnte.token'));
    }

    /**
     * Normalisasi nomor ke format internasional tanpa "+".
     *  081234567890  -> 6281234567890
     *  +62 812-3456-7890 -> 6281234567890
     *  81234567890   -> 6281234567890
     */
    public function normalizeNumber(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '620')) {
            $digits = '62'.substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        // Nomor Indonesia yang wajar: 62 + 8xx (total 10-15 digit)
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    /**
     * Kirim pesan teks.
     *
     * @return array{ok: bool, target: ?string, error: ?string, response: array}
     */
    public function send(string $number, string $message): array
    {
        $target = $this->normalizeNumber($number);

        if (! $target) {
            return [
                'ok' => false,
                'target' => null,
                'error' => 'Nomor WhatsApp tidak valid.',
                'response' => [],
            ];
        }

        if (! $this->isConfigured()) {
            Log::warning('Fonnte: FONNTE_TOKEN belum diset, pesan tidak dikirim.', ['target' => $target]);

            return [
                'ok' => false,
                'target' => $target,
                'error' => 'FONNTE_TOKEN belum diset di .env.',
                'response' => [],
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => (string) config('services.fonnte.token'),
            ])
                ->asForm()
                ->timeout((int) config('services.fonnte.timeout', 15))
                ->post((string) config('services.fonnte.url'), [
                    'target' => $target,
                    'message' => $message,
                    'countryCode' => (string) config('services.fonnte.country_code', '62'),
                ]);
        } catch (ConnectionException $e) {
            Log::error('Fonnte: gagal terhubung.', ['target' => $target, 'error' => $e->getMessage()]);

            return [
                'ok' => false,
                'target' => $target,
                'error' => 'Gagal menghubungi server Fonnte (timeout/koneksi).',
                'response' => [],
            ];
        }

        $json = $response->json() ?? [];
        // Fonnte membalas {"status": true, ...} saat diterima; toleran terhadap "true"/1.
        $ok = $response->successful()
            && filter_var(data_get($json, 'status'), FILTER_VALIDATE_BOOLEAN);

        if (! $ok) {
            $error = data_get($json, 'reason')
                ?? data_get($json, 'detail')
                ?? ('HTTP '.$response->status());

            Log::warning('Fonnte: pengiriman gagal.', [
                'target' => $target,
                'http_status' => $response->status(),
                'response' => $json,
            ]);

            return [
                'ok' => false,
                'target' => $target,
                'error' => is_string($error) ? $error : 'Pengiriman gagal.',
                'response' => $json,
            ];
        }

        Log::info('Fonnte: pesan terkirim.', ['target' => $target]);

        return [
            'ok' => true,
            'target' => $target,
            'error' => null,
            'response' => $json,
        ];
    }

    /**
     * Kirim kredensial akun baru / password baru ke WhatsApp user.
     *
     * @return array{ok: bool, target: ?string, error: ?string, response: array}
     */
    public function sendCredentials(User $user, string $plainPassword): array
    {
        return $this->send((string) $user->whatsapp, $this->credentialsMessage($user, $plainPassword));
    }

    public function credentialsMessage(User $user, string $plainPassword): string
    {
        $appName = config('app.name');
        $loginUrl = rtrim((string) config('app.url'), '/').'/login';

        return implode("\n", [
            "*Akun SSO {$appName}*",
            '',
            "Halo {$user->name}, akun Anda sudah dibuat.",
            '',
            'Email: '.$user->email,
            'NIP: '.($user->nip ?: '-'),
            "Password: {$plainPassword}",
            '',
            "Masuk di: {$loginUrl}",
            '',
            'Demi keamanan, segera ganti password setelah login pertama. Jangan bagikan pesan ini kepada siapa pun.',
        ]);
    }
}
