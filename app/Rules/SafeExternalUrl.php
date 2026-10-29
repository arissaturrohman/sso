<?php

namespace App\Rules;

use App\Services\SloService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * Validasi URL yang akan di-fetch server-side (back-channel logout).
 * Mencegah SSRF ke localhost, IP privat, link-local (169.254.0.0/16),
 * metadata cloud, dan skema selain http/https.
 */
class SafeExternalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('URI tidak valid.');

            return;
        }

        try {
            app(SloService::class)->assertSafeBackchannelUri(trim($value));
        } catch (Throwable) {
            $fail('URI wajib HTTPS dan tidak boleh mengarah ke alamat privat/internal.');
        }
    }
}
