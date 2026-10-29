<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Token;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * POST /oauth/revoke  (RFC 7009 – Token Revocation)
 *
 * Menerima bearer token yang ingin dicabut (atau parameter `token`)
 * dan mematikan access token + refresh token terkait.
 */
class RevokeController extends Controller
{
    public function revoke(Request $request): JsonResponse
    {
        $bearer = $request->bearerToken() ?: $request->input('token');

        if (! $bearer) {
            return response()->json(['error' => 'invalid_request'], 400);
        }

        $accessToken = $this->resolveTokenFromBearer($bearer);

        if (is_null($accessToken)) {
            // Token sudah tidak valid; RFC 7009 tetap respons 200.
            return response()->json(['revoked' => false]);
        }

        Token::withoutTimestamps(function () use ($accessToken) {
            // Cabut access token
            $accessToken->forceFill(['revoked' => true])->save();

            // Cabut refresh token yang melekat
            $accessToken->refreshToken()->first()?->forceFill([
                'revoked' => true,
                'expires_at' => now(),
            ])->save();
        });

        // Tandai pemetaan sesi SSO client ini berakhir — HANYA milik user
        // pemilik token. Tanpa filter user_id, siapa pun yang memegang token
        // untuk client X bisa memutus sesi SSO semua user lain di client X.
        if ($accessToken->user_id) {
            \App\Models\SsoSession::where('user_id', $accessToken->user_id)
                ->where('client_id', $accessToken->client_id)
                ->whereNull('logged_out_at')
                ->update(['logged_out_at' => now(), 'revoked_at' => now()]);
        }

        return response()
            ->json(['revoked' => true])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Validasi bearer JWT via ResourceServer, ambil token dari DB.
     */
    protected function resolveTokenFromBearer(string $bearer): ?Token
    {
        $resourceServer = app(\League\OAuth2\Server\ResourceServer::class);

        // ResourceServer hanya membaca header Authorization — paksa jika
        // token dikirim lewat parameter `token`.
        $httpRequest = request();
        if (! $httpRequest->bearerToken()) {
            $httpRequest->headers->set('Authorization', 'Bearer '.$bearer);
        }

        $psr = (new PsrHttpFactory)->createRequest($httpRequest);

        try {
            $validated = $resourceServer->validateAuthenticatedRequest($psr);
        } catch (\Throwable) {
            return null;
        }

        $tokenEntity = \Laravel\Passport\AccessToken::fromPsrRequest($validated);

        // id access token di database tersimpan pada atribut PSR-7
        return Token::find($tokenEntity->oauth_access_token_id);
    }
}
