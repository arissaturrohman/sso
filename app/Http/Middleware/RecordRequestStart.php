<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mencatat waktu request mulai diproses.
 *
 * Dipakai listener AccessTokenCreated untuk mendeteksi refresh/token request
 * yang START sebelum logout tetapi token-nya BARU dibuat setelah logout.
 * Tanpa ini, race refresh-vs-logout bisa menghasilkan access token baru yang
 * valid hingga 15 menit.
 */
class RecordRequestStart
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('request_started_at', now());

        return $next($request);
    }
}
