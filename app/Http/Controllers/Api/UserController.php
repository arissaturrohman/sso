<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * GET /api/user — data profil user berdasarkan access token.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user('api');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'nip' => $user->nip,
            'token' => [
                'client_id' => $user->currentAccessToken()?->client_id,
                'scopes' => $user->currentAccessToken()?->scopes,
                'expires_at' => $user->currentAccessToken()?->expires_at?->toISOString(),
            ],
        ]);
    }
}
