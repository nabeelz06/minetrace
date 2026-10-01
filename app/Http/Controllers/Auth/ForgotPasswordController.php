<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ForgotPasswordController — permintaan link reset password via email.
 *
 * Selalu menampilkan pesan sukses yang sama baik email terdaftar maupun
 * tidak (mencegah enumerasi akun) — hanya dibedakan lewat log internal.
 */
class ForgotPasswordController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email'    => 'Format email tidak valid.',
        ]);

        $this->checkRateLimit($request);

        $status = Password::sendResetLink($request->only('email'));

        RateLimiter::hit($this->throttleKey($request), 60);

        if ($status === Password::RESET_LINK_SENT) {
            Log::info("Password reset link dikirim untuk email:{$request->email} ip:{$request->ip()}");
        } else {
            // Email tidak terdaftar (atau throttled oleh Password broker) —
            // TIDAK dibocorkan ke user, hanya dicatat di log internal.
            Log::info("Permintaan reset password ditolak broker status:{$status} email:{$request->email} ip:{$request->ip()}");
        }

        return back()->with('status', 'Jika email terdaftar di sistem kami, link reset password sudah dikirim. Silakan cek kotak masuk (dan folder spam) Anda.');
    }

    private function checkRateLimit(Request $request): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            $seconds = RateLimiter::availableIn($this->throttleKey($request));

            throw ValidationException::withMessages([
                'email' => "Terlalu banyak permintaan. Coba lagi dalam {$seconds} detik.",
            ]);
        }
    }

    private function throttleKey(Request $request): string
    {
        return 'forgot-password:' . Str::transliterate(
            Str::lower($request->string('email')) . '|' . $request->ip()
        );
    }
}
