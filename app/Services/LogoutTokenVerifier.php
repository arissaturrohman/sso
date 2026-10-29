<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Implementasi referensi verifikasi logout token di sisi Service Provider.
 *
 * Ini BUKAN dipakai oleh endpoint IdP (IdP hanya menerbitkan token), tetapi
 * disediakan sebagai kontrak yang bisa diuji dan disalin ke aplikasi client.
 *
 * Yang diperiksa (semua wajib):
 *  1. Struktur JWT 3 segmen, header `alg` = RS256 (menolak `none`/HS256), `kid` ada.
 *  2. Signature valid terhadap JWKS IdP.
 *  3. `iss` exact-match issuer IdP.
 *  4. `aud` mengandung client_id SP ini.
 *  5. `iat`/`exp` valid, umur token <= 120 detik + leeway.
 *  6. `events` mengandung backchannel-logout.
 *  7. `nonce` tidak boleh ada.
 *  8. `jti` ada dan belum pernah dipakai (anti-replay, atomic Cache::add).
 *  9. `sub`/`sid` ada; bila diminta, harus cocok dengan sesi lokal.
 */
class LogoutTokenVerifier
{
    public function verify(
        string $jwt,
        string $expectedAudience,
        ?string $expectedSid = null,
        ?string $expectedSub = null
    ): array {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            throw new RuntimeException('Logout token bukan JWT yang valid.');
        }

        $header = json_decode($this->base64UrlDecode($segments[0]), true);

        if (! is_array($header)) {
            throw new RuntimeException('Header logout token tidak valid.');
        }

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new RuntimeException('Algoritma logout token wajib RS256.');
        }

        if (empty($header['kid']) || ! is_string($header['kid'])) {
            throw new RuntimeException('Header logout token wajib memiliki kid.');
        }

        $leeway = max(0, (int) config('sso.logout_token.leeway', 60));
        JWT::$leeway = $leeway;

        try {
            $claims = (array) JWT::decode($jwt, $this->keys($header['kid']));
        } catch (Throwable $e) {
            throw new RuntimeException('Signature logout token tidak valid.', 0, $e);
        }

        $issuer = rtrim((string) config('sso.issuer'), '/');

        if ($issuer === '' || ! hash_equals($issuer, rtrim((string) ($claims['iss'] ?? ''), '/'))) {
            throw new RuntimeException('Issuer logout token tidak cocok.');
        }

        $audiences = (array) ($claims['aud'] ?? []);

        if (! in_array($expectedAudience, $audiences, true)) {
            throw new RuntimeException('Audience logout token tidak cocok.');
        }

        $iat = (int) ($claims['iat'] ?? 0);
        $exp = (int) ($claims['exp'] ?? 0);
        $now = time();

        if ($iat <= 0 || $exp <= 0) {
            throw new RuntimeException('Logout token wajib memiliki iat dan exp.');
        }

        if ($exp <= ($now - $leeway)) {
            throw new RuntimeException('Logout token sudah kedaluwarsa.');
        }

        if ($iat > ($now + $leeway)) {
            throw new RuntimeException('iat logout token berada di masa depan.');
        }

        if (($exp - $iat) > LogoutTokenBuilder::MAX_TTL_SECONDS) {
            throw new RuntimeException('Umur logout token melebihi batas 120 detik.');
        }

        $events = (array) ($claims['events'] ?? []);

        if (! array_key_exists(LogoutTokenBuilder::EVENT, $events)) {
            throw new RuntimeException('Klaim events backchannel-logout tidak ada.');
        }

        if (array_key_exists('nonce', $claims)) {
            throw new RuntimeException('Logout token tidak boleh mengandung nonce.');
        }

        $jti = (string) ($claims['jti'] ?? '');

        if ($jti === '') {
            throw new RuntimeException('Logout token wajib memiliki jti.');
        }

        $sub = isset($claims['sub']) ? (string) $claims['sub'] : null;
        $sid = isset($claims['sid']) ? (string) $claims['sid'] : null;

        if ($sub === null && $sid === null) {
            throw new RuntimeException('Logout token wajib memiliki sub atau sid.');
        }

        if ($expectedSub !== null && ! hash_equals($expectedSub, (string) $sub)) {
            throw new RuntimeException('sub logout token tidak cocok dengan sesi lokal.');
        }

        if ($expectedSid !== null && ! hash_equals($expectedSid, (string) $sid)) {
            throw new RuntimeException('sid logout token tidak cocok dengan sesi lokal.');
        }

        $this->consumeJti($jti, max(1, $exp - $now + $leeway));

        return $claims;
    }

    /**
     * @return Key|array<string, Key>
     */
    protected function keys(string $kid): Key|array
    {
        $publicKey = config('sso.logout_token.public_key');

        if (filled($publicKey)) {
            return new Key(str_replace(['\\n', "\r\n"], ["\n", "\n"], (string) $publicKey), 'RS256');
        }

        $jwks = $this->jwks();
        $knownKids = array_map(
            static fn (array $key) => (string) ($key['kid'] ?? ''),
            (array) ($jwks['keys'] ?? [])
        );

        if (! in_array($kid, $knownKids, true)) {
            // Kemungkinan rotasi key: paksa refresh sekali.
            $jwks = $this->jwks(force: true);
        }

        return JWK::parseKeySet($jwks, 'RS256');
    }

    /**
     * @return array{keys: array<int, array<string, mixed>>}
     */
    protected function jwks(bool $force = false): array
    {
        $uri = config('sso.logout_token.jwks_uri');

        if (! filled($uri)) {
            $uri = rtrim((string) config('sso.issuer'), '/').'/oauth/jwks';
        }

        $cacheKey = 'sso:jwks:'.sha1((string) $uri);

        if ($force) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3600, function () use ($uri) {
            try {
                $response = Http::timeout(3)->acceptJson()->get((string) $uri);

                if (! $response->successful()) {
                    throw new RuntimeException('JWKS endpoint mengembalikan HTTP '.$response->status());
                }

                $jwks = $response->json();

                if (! is_array($jwks) || empty($jwks['keys'])) {
                    throw new RuntimeException('JWKS tidak valid.');
                }

                return $jwks;
            } catch (Throwable $e) {
                Log::error('[SSO] Gagal mengambil JWKS', ['uri' => $uri, 'error' => $e->getMessage()]);
                throw new RuntimeException('JWKS IdP tidak dapat diambil.', 0, $e);
            }
        });
    }

    protected function consumeJti(string $jti, int $ttl): void
    {
        $store = Cache::store(config('sso.logout_token.jti_store'));

        try {
            $fresh = $store->add('sso:logout-jti:'.hash('sha256', $jti), 1, $ttl);
        } catch (Throwable $e) {
            Log::error('[SSO] Cache anti-replay jti tidak tersedia', ['error' => $e->getMessage()]);
            throw new RuntimeException('Anti-replay jti tidak tersedia.', 0, $e);
        }

        if (! $fresh) {
            throw new RuntimeException('Logout token sudah pernah dipakai (replay).');
        }
    }

    protected function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
