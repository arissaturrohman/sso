<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Services\PassportKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GET /oauth/jwks — JSON Web Key Set berisi kunci publik Passport.
 *
 * Client App memakai endpoint ini untuk memverifikasi logout_token dari IdP.
 * `kid` di sini HARUS sama dengan `kid` pada header logout token; keduanya
 * diambil dari PassportKeyService (termasuk saat key berasal dari env, bukan
 * file storage).
 */
class JwksController extends Controller
{
    public function jwks(PassportKeyService $keys): JsonResponse
    {
        try {
            $jwk = $keys->jwk();
        } catch (Throwable $e) {
            Log::error('[SSO] Gagal membangun JWKS', ['error' => $e->getMessage()]);

            abort(500, 'JWKS tidak tersedia.');
        }

        return response()
            ->json(['keys' => [$jwk]])
            ->header('Cache-Control', 'public, max-age=3600, must-revalidate')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
