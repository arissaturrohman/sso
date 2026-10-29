<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\Passport;

class SsoSession extends Model
{
    use HasUuids;

    /**
     * Hanya field non-sensitif yang boleh mass-assign. `user_id`,
     * `revoked_at`, dan `logged_out_at` wajib di-set lewat forceFill()
     * di service agar tidak bisa dimanipulasi dari request.
     */
    protected $fillable = [
        'client_id',
        'session_hash',
        'ip_address',
        'user_agent',
        'device_name',
        'last_activity_at',
        'expires_at',
    ];

    protected $hidden = [
        'session_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
            'logged_out_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        // client_id adalah UUID Passport client
        return $this->belongsTo(Passport::clientModel(), 'client_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at')->whereNull('logged_out_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
