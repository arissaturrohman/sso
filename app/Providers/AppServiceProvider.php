<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Produksi di belakang HTTPS (Nginx/Apache reverse proxy):
        // semua URL yang digenerate Laravel wajib https
        if ($this->app->isProduction()) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
            \Illuminate\Support\Facades\URL::forceRootUrl(rtrim(config('app.url'), '/'));
        }

        $this->configurePassport();
        $this->configureRateLimiters();
        $this->configureTokenSafety();
    }

    /**
     * Tutup race "refresh token vs logout" dan penerbitan token untuk akun
     * yang sudah dinonaktifkan.
     *
     * AccessTokenCreated tidak membawa waktu mulai request, jadi middleware
     * RecordRequestStart menyimpannya di request attribute. Jika logout
     * terjadi SETELAH request refresh dimulai tetapi token baru selesai
     * dibuat setelahnya, token tersebut langsung dicabut.
     */
    protected function configureTokenSafety(): void
    {
        Event::listen(AccessTokenCreated::class, function (AccessTokenCreated $event) {
            if (empty($event->userId)) {
                return;
            }

            $user = User::find($event->userId);

            if (! $user) {
                return;
            }

            $requestStart = request()->attributes->get('request_started_at');
            $epoch = $user->tokens_valid_after;
            $stale = $requestStart !== null
                && $epoch !== null
                && $epoch->greaterThanOrEqualTo($requestStart->copy()->startOfSecond());

            if ($user->status !== 'active' || $stale) {
                DB::table('oauth_access_tokens')
                    ->where('id', $event->tokenId)
                    ->update(['revoked' => true]);

                DB::table('oauth_refresh_tokens')
                    ->where('access_token_id', $event->tokenId)
                    ->update(['revoked' => true]);

                Log::warning('[SSO] Token baru langsung dicabut', [
                    'user_id' => $user->id,
                    'client_id' => $event->clientId,
                    'reason' => $user->status !== 'active' ? 'account_inactive' : 'issued_after_logout',
                ]);
            }
        });
    }

    /**
     * Konfigurasi OAuth2 server (Passport / league/oauth2-server).
     */
    protected function configurePassport(): void
    {
        // Workaround Passport 13.0.0: static $keyPath harus diinisialisasi
        // sebelum dipakai (bug "must not be accessed before initialization").
        Passport::loadKeysFrom(storage_path());

        // Model Client kustom (skipsAuthorization utk client trusted)
        Passport::useClientModel(\App\Models\Client::class);

        // View halaman consent/otorisasi
        Passport::authorizationView(fn (array $params) => view('oauth.authorize', $params));

        // Scope yang dikenali IdP
        Passport::tokensCan([
            '*' => 'Akses penuh (seluruh izin)',
            'openid' => 'Otentikasi OpenID Connect',
            'profile' => 'Membaca profil dasar (nama, NIP)',
            'email' => 'Membaca alamat email',
        ]);

        // Jangan default ke '*' (full access). Client yang tidak mengirim
        // scope hanya mendapat scope minimum; '*' tetap harus diminta eksplisit
        // dan disetujui user di consent screen.
        Passport::setDefaultScope(['openid', 'profile', 'email']);

        // Masa aktif token (praktik keamanan terbaik: singkat + refresh rotation)
        Passport::tokensExpireIn(now()->addMinutes(15));
        Passport::refreshTokensExpireIn(now()->addDays(14));

        // league oauth2-server: refresh token dipakai -> langsung dicabut
        Passport::$revokeRefreshTokenAfterUse = true;

        // PKCE & revoke
        Passport::$ignoreCsrfToken = false;
    }

    /**
     * Rate limiter untuk endpoint sensitif (brute force protection).
     */
    protected function configureRateLimiters(): void
    {
        // POST /login — limit ketat per IP + identifier yang dicoba
        RateLimiter::for('auth', function (Request $request) {
            $identifier = hash('sha256', (string) $request->input('identifier'));

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('acct:'.$identifier),
            ];
        });

        // POST /oauth/token — 15x/menit per kombinasi client & IP
        RateLimiter::for('oauth-token', function (Request $request) {
            return Limit::perMinute(15)
                ->by($request->input('client_id', 'unknown').'|'.$request->ip());
        });

        RateLimiter::for('default', fn (Request $request) => Limit::perMinute(60)->by($request->ip() ?: $request->ip()));
    }
}
