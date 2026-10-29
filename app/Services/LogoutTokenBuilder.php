<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Membangun & menandatangani "logout token" (OIDC Back-Channel Logout).
 *
 * Kontrol keamanan yang WAJIB dipertahankan:
 *  - Hanya RS256 (tidak pernah `none` / HS256).
 *  - `exp` <= 120 detik dan `iat` diisi.
 *  - `aud` berisi client_id SP tujuan (bukan semua client).
 *  - `jti` unik untuk setiap pengiriman (anti-replay di SP).
 *  - `sub` dan/atau `sid` wajib ada.
 *  - Tidak boleh mengandung `nonce` (spec melarang).
 */
class LogoutTokenBuilder
{
    public const MAX_TTL_SECONDS = 120;

    public const EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public function __construct(private readonly PassportKeyService $keys) {}

    public function build(
        string $issuer,
        string $audienceClientId,
        ?int $userId,
        string $sid,
        string $jti
    ): string {
        $issuer = rtrim(trim($issuer), '/');
        $audienceClientId = trim($audienceClientId);
        $sid = trim($sid);
        $jti = trim($jti);

        if ($issuer === '' || ! filter_var($issuer, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Issuer logout token tidak valid.');
        }

        if ($audienceClientId === '') {
            throw new InvalidArgumentException('Audience logout token wajib diisi.');
        }

        if ($jti === '') {
            throw new InvalidArgumentException('jti logout token wajib diisi.');
        }

        if ($sid === '' && $userId === null) {
            throw new InvalidArgumentException('Logout token wajib memiliki sub atau sid.');
        }

        $ttl = (int) config('sso.logout_token.ttl', self::MAX_TTL_SECONDS);
        $ttl = max(30, min($ttl, self::MAX_TTL_SECONDS));

        $now = now()->timestamp;

        $claims = [
            'iss' => $issuer,
            'aud' => [$audienceClientId],
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => $jti,
            'events' => [
                self::EVENT => new \stdClass,
            ],
        ];

        // Spec: sub dan/atau sid. Jangan mengirim sub=null/string kosong.
        if ($userId !== null) {
            $claims['sub'] = (string) $userId;
        }

        if ($sid !== '') {
            $claims['sid'] = $sid;
        }

        // Jangan pernah menambahkan `nonce` ke logout token.
        return JWT::encode(
            $claims,
            $this->keys->privateKeyPem(),
            'RS256',
            $this->keys->keyId()
        );
    }

    public static function newJti(): string
    {
        return Str::uuid()->toString();
    }
}
