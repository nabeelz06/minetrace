<?php

namespace App\Http\Middleware;

use App\Support\RfidApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireRoles — penjaga peran untuk API ber-token (Sanctum).
 * Middleware `role:` milik Spatie memakai guard `web` sehingga tidak cocok untuk token;
 * ini memeriksa $request->user() apa pun guard-nya. Pemakaian: ->middleware('roles:a,b,c')
 */
class RequireRoles
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole($roles)) {
            return RfidApi::fail('forbidden', 403);
        }

        return $next($request);
    }
}
