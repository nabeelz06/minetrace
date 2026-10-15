<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ResetPasswordController — set password baru memakai token dari email.
 */
class ResetPasswordController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token'                 => ['required', 'string'],
            'email'                 => ['required', 'string', 'email'],
            'password'              => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()->symbols()],
        ], [
            'email.required'    => 'Email wajib diisi.',
            'password.required' => 'Password baru wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'force_password_change' => false,   // password baru sudah dipilih sendiri
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            Log::warning("Reset password gagal status:{$status} email:{$request->email} ip:{$request->ip()}");

            throw ValidationException::withMessages([
                'email' => $this->translateStatus($status),
            ]);
        }

        Log::info("Password berhasil direset untuk email:{$request->email} ip:{$request->ip()}");

        return redirect()->route('login')
            ->with('status', 'Password berhasil diubah. Silakan login dengan password baru Anda.');
    }

    private function translateStatus(string $status): string
    {
        return match ($status) {
            Password::INVALID_TOKEN => 'Link reset password tidak valid atau sudah kedaluwarsa. Silakan minta link baru.',
            Password::INVALID_USER  => 'Email tidak terdaftar di sistem kami.',
            Password::RESET_THROTTLED => 'Terlalu banyak percobaan. Coba lagi dalam beberapa saat.',
            default => 'Gagal mereset password. Silakan coba lagi atau minta link baru.',
        };
    }
}
