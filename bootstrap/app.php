<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'security-headers' => \App\Http\Middleware\SecurityHeaders::class,
            'pkce' => \App\Http\Middleware\EnforcePkce::class,
            'track-authorize' => \App\Http\Middleware\TrackAuthorizeRequest::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'active' => \App\Http\Middleware\EnsureAccountIsActive::class,
        ]);

        // Waktu mulai request harus tercatat sebelum middleware lain (dipakai
        // listener AccessTokenCreated untuk menutup race refresh vs logout).
        $middleware->prepend(\App\Http\Middleware\RecordRequestStart::class);

        // Pasang security headers + enforcement PKCE global
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->append(\App\Http\Middleware\EnforcePkce::class);

        // Dalam group web (butuh session):
        //  0. tolak akun non-aktif / sesi yang sudah logout
        //  1. paksa ganti password sebelum halaman lain
        //  2. tracking authorize (hanya setelah lolos cek password)
        //  + SEO: share title/description/robots/canonical/JSON-LD per route
        //    (harus pertama agar data tersedia sebelum view di-render)
        $middleware->web(append: [
            \App\Http\Middleware\SeoMeta::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\ForcePasswordChange::class,
            \App\Http\Middleware\TrackAuthorizeRequest::class,
        ]);

        // CSRF hanya dilepas untuk revoke (server-to-server, no session)
        $middleware->validateCsrfTokens(except: [
            'oauth/revoke',
        ]);

        // Terima header X-Forwarded-* dari reverse proxy (HTTPS termination)
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Route API selalu render error sebagai JSON (bukan redirect login)
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $e) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
