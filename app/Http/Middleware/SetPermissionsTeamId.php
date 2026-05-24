<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetPermissionsTeamId
 *
 * Wajib dijalankan SEBELUM middleware role: dari Spatie.
 * Fungsi: set team context (company_id) agar Spatie tahu
 * role mana yang dicek (per-company, bukan global).
 *
 * Tanpa ini, Spatie tidak bisa resolve role di teams mode.
 */
class SetPermissionsTeamId
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            // Super admin: tidak perlu team context
            if ($user->company_id) {
                setPermissionsTeamId($user->company_id);
            }
        }

        return $next($request);
    }
}