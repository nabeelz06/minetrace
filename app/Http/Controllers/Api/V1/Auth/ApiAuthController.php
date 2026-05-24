<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * ApiAuthController — authentikasi untuk API (Sanctum token).
 *
 * Mobile app dan hardware device menggunakan endpoint ini untuk
 * mendapatkan token sebelum mengakses endpoint lain.
 */
class ApiAuthController extends Controller
{
    /**
     * Login — return Sanctum token.
     *
     * POST /api/v1/auth/login
     * Body: { email, password, device_name }
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'       => 'required|email',
            'password'    => 'required|string',
            'device_name' => 'nullable|string|max:100',
        ]);

        $user = User::where('email', $request->email)
            ->where('is_active', true)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        // Revoke token lama untuk device yang sama
        $deviceName = $request->device_name ?? 'api-client';
        $user->tokens()->where('name', $deviceName)->delete();

        // Buat token baru
        $token = $user->createToken($deviceName)->plainTextToken;

        // Update last login
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'token'      => $token,
                'token_type' => 'Bearer',
                'user'       => [
                    'id'         => $user->id,
                    'name'       => $user->name,
                    'email'      => $user->email,
                    'role'       => $user->getRoleNames()->first(),
                    'company_id' => $user->company_id,
                    'language'   => $user->language,
                ],
            ],
            'message' => 'Login berhasil.',
        ]);
    }

    /**
     * Logout — revoke current token.
     *
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'Logout berhasil.',
        ]);
    }

    /**
     * Get current user info.
     *
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('company');

        return response()->json([
            'success' => true,
            'data'    => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'role'        => $user->getRoleNames()->first(),
                'company'     => $user->company ? [
                    'id'           => $user->company->id,
                    'name'         => $user->company->name,
                    'code'         => $user->company->code,
                    'primary_color'=> $user->company->primary_color,
                ] : null,
                'language'    => $user->language,
                'avatar_url'  => $user->avatar_url,
            ],
            'message' => 'OK',
        ]);
    }

    /**
     * Refresh token — buat token baru dan revoke lama.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user        = $request->user();
        $currentName = $user->currentAccessToken()->name;

        $user->currentAccessToken()->delete();
        $newToken = $user->createToken($currentName)->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => ['token' => $newToken, 'token_type' => 'Bearer'],
            'message' => 'Token diperbarui.',
        ]);
    }
}