<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EnsurePbjAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        // Jika user tidak login, redirect ke login
        if (!$user) {
            return redirect()->route('login');
        }
        
        // Jika user bukan PBJ, tampilkan error
        if (!$user->is_pbj) {
            if ($request->wantsJson()) {
                return response()->json([
                    'error' => 'Access denied. PBJ staff access required.'
                ], 403);
            }
            
            return response()->view('errors.pbj-access-denied', [], 403);
        }
        
        return $next($request);
    }
}