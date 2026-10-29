<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Satu sumber kebenaran untuk key Passport yang dipakai logout token.
 *
 * Sebelumnya LogoutTokenBuilder memakai private key dari config sementara
 * JwksController selalu membaca storage/oauth-public.key. Jika deployment
 * memakai PASSPORT_PRIVATE_KEY/PASSPORT_PUBLIC_KEY dari env, JWKS tidak
 * cocok dengan token yang ditandatangani sehingga verifikasi di SP gagal.
 */
class PassportKeyService
{
    private ?array $jwk = null;

    public function privateKeyPem(): string
    {
        $key = config('passport.private_key');

        if (filled($key)) {
            return $this->normalizePem((string) $key);
        }

        $path = storage_path('oauth-private.key');

        if (! File::exists($path)) {
            throw new RuntimeException('Passport private key tidak ditemukan.');
        }

        return File::get($path);
    }

    public function publicKeyPem(): string
    {
        $key = config('passport.public_key');

        if (filled($key)) {
            return $this->normalizePem((string) $key);
        }

        $path = storage_path('oauth-public.key');

        if (File::exists($path)) {
            return File::get($path);
        }

        // Turunkan public key dari private key bila file public tidak ada.
        $details = openssl_pkey_get_details(
            openssl_pkey_get_private($this->privateKeyPem())
        );

        if ($details === false || empty($details['key'])) {
            throw new RuntimeException('Gagal menurunkan public key Passport.');
        }

        return $details['key'];
    }

    /**
     * JWK publik (RFC 7517) untuk endpoint /oauth/jwks.
     *
     * @return array{kty:string,use:string,alg:string,kid:string,n:string,e:string}
     */
    public function jwk(): array
    {
        if ($this->jwk !== null) {
            return $this->jwk;
        }

        $details = openssl_pkey_get_details(
            openssl_pkey_get_public($this->publicKeyPem())
        );

        if ($details === false || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('Public key Passport bukan RSA yang valid.');
        }

        $configuredKid = config('sso.logout_token.kid');

        $jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => filled($configuredKid)
                ? (string) $configuredKid
                : $this->thumbprint($details['rsa']['n'], $details['rsa']['e']),
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ];

        return $this->jwk = $jwk;
    }

    public function keyId(): string
    {
        $configured = config('sso.logout_token.kid');

        if (filled($configured)) {
            return (string) $configured;
        }

        return $this->jwk()['kid'];
    }

    /**
     * RFC 7638 JWK thumbprint: hash dari JSON {e,kty,n} tanpa whitespace.
     */
    private function thumbprint(string $modulus, string $exponent): string
    {
        $canonical = json_encode([
            'e' => $this->base64UrlEncode($exponent),
            'kty' => 'RSA',
            'n' => $this->base64UrlEncode($modulus),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->base64UrlEncode(hash('sha256', $canonical, true));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function normalizePem(string $key): string
    {
        return str_replace(['\\n', "\r\n"], ["\n", "\n"], $key);
    }
}
