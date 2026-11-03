<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccess extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'client_id',
        'opd_id',
        'is_allowed',
    ];

    protected $casts = [
        'is_allowed' => 'boolean',
    ];

    /**
     * Get the user that owns the client access.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the client that owns the client access.
     */
    public function client()
    {
        return $this->belongsTo(\Laravel\Passport\Passport::clientModel(), 'client_id');
    }

    /**
     * Get the OPD that owns the client access.
     */
    public function opd()
    {
        return $this->belongsTo(\App\Models\Opd::class, 'opd_id');
    }
}