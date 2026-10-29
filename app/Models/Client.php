<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as PassportClient;
use Laravel\Passport\Scope;

class Client extends PassportClient
{
    protected $guarded = ['id'];

    /**
     * Client yang didaftarkan sebagai "trusted" (first-party internal)
     * akan otomatis melewati halaman consent.
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return (bool) $this->trusted;
    }
}
