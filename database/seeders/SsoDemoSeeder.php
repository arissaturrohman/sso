<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;

/**
 * Seeder IdP SSO:
 *  - Membuat 2 user demo (login via email ATAU NIP)
 *  - Membuat client OAuth2 (service provider pupuk demo) dengan
 *    back-channel logout URI.
 */
class SsoDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ---------- USERS ----------
        if (! User::where('email', 'admin@sso.test')->exists()) {
            User::create([
                'name' => 'Admin SSO',
                'email' => 'admin@sso.test',
                'nip' => '199900001',
                'password' => 'password123',
                'status' => 'active',
                'role' => 'admin',
                'email_verified_at' => now(),
            ]);
        } else {
            // Pastikan role admin tetap admin (idempotent)
            User::where('email', 'admin@sso.test')->update(['role' => 'admin']);
        }

        if (! User::where('email', 'budi@sso.test')->exists()) {
            User::create([
                'name' => 'Budi Setiawan',
                'email' => 'budi@sso.test',
                'nip' => '1995000002',
                'password' => 'password123',
                'status' => 'active',
                'role' => 'user',
                'email_verified_at' => now(),
            ]);
        }

        // ---------- OAuth2 CLIENT (Service Provider demo) ----------
        $repo = app(ClientRepository::class);

        $client = $repo->findActive(
            DB::table('oauth_clients')->where('name', 'Aplikasi Demo (Client App)')->value('id') ?? ''
        );

        if (! $client) {
            $client = $repo->createAuthorizationCodeGrantClient(
                name: 'Aplikasi Demo (Client App)',
                redirectUris: ['http://localhost:8010/auth/callback'],
                confidential: true
            );
        }

        // Tambahkan metadata SLO kustom (idempotent — selalu disinkronkan)
        $client->forceFill([
            'trusted' => true,
            'landing_url' => 'http://localhost:8010/',
            'backchannel_logout_uri' => 'http://localhost:8010/sso/backchannel-logout',
            'frontchannel_logout_uri' => null,
        ])->save();

        $this->command->table(
            ['Keterangan', 'Nilai'],
            [
                ['Client ID', $client->id],
                ['Client Secret', $client->plainSecret ?? '(sudah diset saat pembuatan; lihat log berikut)'],
                ['Redirect URI', 'http://localhost:8010/auth/callback'],
                ['Back-channel Logout URI', 'http://localhost:8010/sso/backchannel-logout'],
                ['User demo email', 'admin@sso.test / password123'],
                ['User demo NIP', '199900001 / password123'],
            ]
        );
    }
}
