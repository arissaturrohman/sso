<?php

namespace App\Rules;

use App\Services\FonnteService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validasi nomor WhatsApp Indonesia (08xx / +628xx / 628xx).
 */
class WhatsAppNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || app(FonnteService::class)->normalizeNumber($value) === null) {
            $fail('Nomor WhatsApp tidak valid. Contoh: 081234567890.');
        }
    }
}
