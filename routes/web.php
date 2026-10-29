<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\SsoLogoutController;
use App\Http\Controllers\OAuth\JwksController;
use App\Http\Controllers\OAuth\RevokeController;
use App\Http\Controllers\OAuth\UserInfoController;
use App\Http\Controllers\SessionsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Halaman publik IdP
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => view('home'))->name('home');

/*
|--------------------------------------------------------------------------
| Login / Logout IdP (Single Sign-On utama)
|--------------------------------------------------------------------------
*/
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login'])->middleware('guest', 'throttle:auth')->name('login.attempt');

/*
|--------------------------------------------------------------------------
| Single Logout (SLO)
|--------------------------------------------------------------------------
| POST /logout   : dipicu dari dashboard IdP  — global logout + back-channel
| GET  /sso/logout : dipicu oleh Client App — validasi redirect & client
*/
Route::post('/logout', [SsoLogoutController::class, 'webLogout'])
    ->middleware(['auth', 'active', 'throttle:10,1'])
    ->name('logout');

Route::get('/sso/logout', [SsoLogoutController::class, 'redirectLogout'])
    ->middleware('throttle:30,1')
    ->name('sso.logout');

/*
|--------------------------------------------------------------------------
| Endpoint OAuth "resource" (dipakai Client App server-to-server)
|--------------------------------------------------------------------------
| /oauth/userinfo : OIDC UserInfo (claims profil)
| /oauth/revoke   : RFC 7009 Token Revocation (CSRF dilepas utk request ini)
| /oauth/jwks     : Public key utk verifikasi logout_token (back-channel SLO)
*/
Route::get('/oauth/userinfo', [UserInfoController::class, 'userinfo'])
    ->middleware(['auth:api', 'active'])
    ->name('oauth.userinfo');

Route::post('/oauth/revoke', [RevokeController::class, 'revoke'])
    ->middleware('throttle:oauth-token')
    ->name('oauth.revoke');

Route::get('/oauth/jwks', [JwksController::class, 'jwks'])
    ->name('oauth.jwks');

/*
|--------------------------------------------------------------------------
| Panel Admin (manajemen user & aplikasi klien) — role admin saja
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');
    Route::resource('users', Admin\UserController::class)->except(['show']);
    Route::post('users/{user}/send-password', [Admin\UserController::class, 'sendPassword'])
        ->name('users.send-password');
    Route::resource('clients', Admin\OAuthClientController::class)->except(['show']);
    Route::get('clients/{client}/secret', [Admin\OAuthClientController::class, 'secret'])->name('clients.secret');
    Route::post('clients/{client}/regenerate-secret', [Admin\OAuthClientController::class, 'regenerateSecret'])
        ->name('clients.regenerate-secret');
});

/*
|--------------------------------------------------------------------------
| Dashboard & manajemen sesi/perangkat
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    // Ubah password (wajib sebelum mengakses halaman lain bila ditandai admin)
    Route::get('/password/change', [PasswordChangeController::class, 'edit'])->name('password.change');
    Route::put('/password/change', [PasswordChangeController::class, 'update'])->name('password.change.update');

    Route::get('/dashboard', function () {
        $clientModel = \Laravel\Passport\Passport::clientModel();

        // Aplikasi klien aktif yang bisa dibuka user dari dashboard IdP
        $apps = $clientModel::query()
            ->where('revoked', false)
            ->whereNotNull('landing_url')
            ->orderBy('name')
            ->get();

        return view('dashboard', compact('apps'));
    })->name('dashboard');
    Route::get('/sessions', [SessionsController::class, 'index'])->name('sessions.index');
    Route::delete('/sessions/{session}', [SessionsController::class, 'destroy'])
        ->middleware('throttle:30,1')
        ->name('sessions.destroy');
});
