<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Jobs\SendBackChannelLogout;
use App\Models\Client;
use App\Models\SsoSession;
use App\Models\User;
use App\Services\LogoutTokenBuilder;
use App\Services\LogoutTokenVerifier;
use App\Services\PassportKeyService;
use App\Services\SloService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SloSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeClient(string $id = '11111111-1111-1111-1111-111111111111'): Client
    {
        return Client::forceCreate([
            'id' => $id,
            'name' => 'SP Demo',
            'secret' => 'secret',
            'provider' => null,
            'redirect_uris' => ['https://sp.example/callback'],
            'grant_types' => ['authorization_code'],
            'revoked' => false,
            'trusted' => false,
            'backchannel_logout_uri' => 'http://localhost:8010/sso/backchannel-logout',
            'frontchannel_logout_uri' => null,
            'landing_url' => null,
        ]);
    }

    private function makeUser(string $email = 'user@example.test'): User
    {
        return User::create([
            'name' => 'User',
            'email' => $email,
            'nip' => '1995000002',
            'password' => 'password123',
            'status' => 'active',
            'role' => 'user',
        ]);
    }

    public function test_post_logout_redirect_rejects_external_origin(): void
    {
        $this->get('/sso/logout?post_logout_redirect_uri=https://evil.example/callback')
            ->assertStatus(400);

        $this->get('/sso/logout?post_logout_redirect_uri=//evil.example/callback')
            ->assertStatus(400);
    }

    public function test_authenticated_logout_rejects_external_redirect(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/logout', ['post_logout_redirect_uri' => 'https://evil.example/'])
            ->assertStatus(400);
    }

    public function test_post_logout_redirect_must_match_registered_client_uri(): void
    {
        $client = $this->makeClient();

        $this->get('/sso/logout?client_id='.$client->id.'&post_logout_redirect_uri=https://evil.example/')
            ->assertStatus(400);

        $this->get('/sso/logout?client_id='.$client->id.'&post_logout_redirect_uri='.urlencode('https://sp.example/callback').'&state=abc123')
            ->assertRedirect('https://sp.example/callback?state=abc123');
    }

    public function test_jwks_kid_matches_logout_token_header(): void
    {
        $keys = app(PassportKeyService::class);

        $this->get('/oauth/jwks')
            ->assertOk()
            ->assertJsonPath('keys.0.kid', $keys->keyId());

        $token = app(LogoutTokenBuilder::class)->build(
            config('sso.issuer'),
            'client-1',
            123,
            'sid-1',
            LogoutTokenBuilder::newJti()
        );

        $header = json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);

        $this->assertSame($keys->keyId(), $header['kid']);
        $this->assertSame('RS256', $header['alg']);
    }

    public function test_global_logout_revokes_tokens_sessions_and_dispatches_backchannel(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        $client = $this->makeClient();

        $session = SsoSession::forceCreate([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'session_hash' => hash('sha256', 'browser-session'),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'device_name' => 'PHPUnit',
            'last_activity_at' => now(),
            'expires_at' => now()->addHour(),
            'logged_out_at' => null,
            'revoked_at' => null,
        ]);

        DB::table('oauth_access_tokens')->insert([
            'id' => 'access-token-1',
            'user_id' => $user->id,
            'client_id' => $client->id,
            'name' => 'test',
            'scopes' => '[]',
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        DB::table('oauth_refresh_tokens')->insert([
            'id' => 'refresh-token-1',
            'access_token_id' => 'access-token-1',
            'revoked' => false,
            'expires_at' => now()->addDays(14),
        ]);

        app(SloService::class)->logout($user, 'sid-test', 'idp');

        $this->assertDatabaseHas('oauth_access_tokens', ['id' => 'access-token-1', 'revoked' => true]);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['id' => 'refresh-token-1', 'revoked' => true]);
        $this->assertNotNull($session->fresh()->logged_out_at);
        $this->assertNotNull($user->fresh()->tokens_valid_after);

        Queue::assertPushed(SendBackChannelLogout::class, function (SendBackChannelLogout $job) use ($client, $session) {
            return $job->clientId === $client->id
                && $job->sid === $session->session_hash
                && $job->backchannelUri === $client->backchannel_logout_uri;
        });
    }

    public function test_inactive_user_is_rejected_by_middleware(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['status' => 'inactive'])->save();

        $request = Request::create('/api/user');
        $request->setUserResolver(fn () => $user->fresh());

        $response = (new EnsureAccountIsActive)->handle(
            $request,
            fn () => response('ok')
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_logout_token_rejects_replay_tampering_expired_and_alg_none(): void
    {
        $keys = app(PassportKeyService::class);
        config(['sso.logout_token.public_key' => $keys->publicKeyPem()]);

        $jti = LogoutTokenBuilder::newJti();
        $token = app(LogoutTokenBuilder::class)->build(
            config('sso.issuer'),
            'client-1',
            123,
            'sid-1',
            $jti
        );

        $verifier = app(LogoutTokenVerifier::class);
        $claims = $verifier->verify($token, 'client-1');

        $this->assertSame(['client-1'], $claims['aud']);
        $this->assertSame('sid-1', $claims['sid']);

        $this->expectException(RuntimeException::class);
        $verifier->verify($token, 'client-1'); // replay
    }

    public function test_logout_token_rejects_wrong_audience(): void
    {
        $keys = app(PassportKeyService::class);
        config(['sso.logout_token.public_key' => $keys->publicKeyPem()]);

        $token = app(LogoutTokenBuilder::class)->build(
            config('sso.issuer'),
            'client-A',
            123,
            'sid-1',
            LogoutTokenBuilder::newJti()
        );

        $this->expectException(RuntimeException::class);
        app(LogoutTokenVerifier::class)->verify($token, 'client-B');
    }

    public function test_logout_token_rejects_expired_and_alg_none(): void
    {
        $keys = app(PassportKeyService::class);
        config(['sso.logout_token.public_key' => $keys->publicKeyPem()]);

        $verifier = app(LogoutTokenVerifier::class);

        $expired = JWT::encode([
            'iss' => config('sso.issuer'),
            'aud' => ['client-1'],
            'iat' => time() - 600,
            'exp' => time() - 300,
            'jti' => 'expired-jti',
            'sub' => '123',
            'events' => [LogoutTokenBuilder::EVENT => new \stdClass],
        ], $keys->privateKeyPem(), 'RS256', $keys->keyId());

        try {
            $verifier->verify($expired, 'client-1');
            $this->fail('Expired logout token diterima.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'kid' => 'x'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'iss' => config('sso.issuer'),
            'aud' => ['client-1'],
            'iat' => time(),
            'exp' => time() + 60,
            'jti' => 'forged',
            'sub' => '123',
        ])), '+/', '-_'), '=');

        $this->expectException(RuntimeException::class);
        $verifier->verify($header.'.'.$payload.'.', 'client-1');
    }
}
