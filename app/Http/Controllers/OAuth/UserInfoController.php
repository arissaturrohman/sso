<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /oauth/userinfo — endpoint identik dengan OIDC UserInfo.
 * Wajib bearer access token (diproteksi middleware auth:api).
 */
class UserInfoController extends Controller
{
    public function userinfo(Request $request): JsonResponse
    {
        $user = $request->user('api');

        return response()->json([
            'sub' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => ! is_null($user->email_verified_at),
            'nip' => $user->nip,
            'updated_at' => $user->updated_at?->toISOString(),
        ]);
    }
}
