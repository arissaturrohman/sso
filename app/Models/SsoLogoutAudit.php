<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SsoLogoutAudit extends Model
{
    protected $table = 'sso_logout_audit';

    protected $fillable = [
        'user_id',
        'client_id',
        'triggered_by',
        'method',
        'logout_token_jti',
        'status',
        'http_status',
        'response',
    ];
}
