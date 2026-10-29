<?php

namespace App\Services;

/**
 * Generator password acak untuk akun baru.
 * Alfabet sengaja tanpa karakter ambigu (0/O, 1/l/I) agar mudah
 * diketik ulang dari pesan WhatsApp.
 */
class PasswordGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public static function generate(int $length = 12): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }
}
