<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Laravel\Passport\Token;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'nip',
        'whatsapp',
        'password',
        'status',
        'role',
        'last_login_at',
        'last_login_ip',
        'password_sent_at',
        'password_changed_at',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_sent_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
            // Epoch keamanan: token yang created_at <= nilai ini ditolak.
            'tokens_valid_after' => 'datetime',
        ];
    }

    /**
     * Normalisasi NIP: trim, buang spasi/titik/strip. Mencegah "collision"
     * semu saat import (mis. "1995 000002" vs "1995000002") dan memastikan
     * login via NIP konsisten.
     */
    protected function nip(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value === null || trim((string) $value) === '') {
                    return null;
                }

                return preg_replace('/[\s.\-]+/u', '', trim((string) $value));
            }
        );
    }

    /**
     * Email selalu lowercase agar unique index tidak bisa dilewati dengan
     * perbedaan kapitalisasi (tergantung collation DB).
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => filled($value) ? strtolower(trim((string) $value)) : $value
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Token akses aktif milik user pada sebuah client (untuk prose logout).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Token>
     */
    public function activeTokensForClient(string $clientId)
    {
        return $this->tokens()->where('client_id', $clientId)->where('revoked', false)->get();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Nomor WhatsApp siap tampil (628xx -> 08xx).
     */
    public function whatsappDisplay(): ?string
    {
        if (! $this->whatsapp) {
            return null;
        }

        return str_starts_with($this->whatsapp, '62')
            ? '0'.substr($this->whatsapp, 2)
            : $this->whatsapp;
    }

    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    public function ssoSessions()
    {
        return $this->hasMany(SsoSession::class);
    }
}
