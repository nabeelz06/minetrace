<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsurePasswordIsChanged — pengguna dengan `force_password_change = true` (akun baru buatan admin,
 * kata sandi bawaan seeder, atau hasil `users:require-password-change`) hanya boleh membuka halaman
 * ganti password dan keluar (logout) sampai password diganti. Sebelumnya flag ini ada tetapi tidak ditegakkan.
 */
class EnsurePasswordIsChanged
{
    /** Rute yang tetap boleh diakses selama wajib ganti password. */
    private const ALLOWED = ['password.change', 'password.change.update', 'logout', 'user.language', 'user.dark-mode'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->force_password_change && ! $request->routeIs(...self::ALLOWED)) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json([
                    'success' => false, 'code' => 'password_change_required',
                    'message' => 'Anda harus mengganti password terlebih dahulu.',
                    'message_en' => 'You must change your password first.',
                ], 403);
            }

            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
