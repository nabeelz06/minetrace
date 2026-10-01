<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * LoginController — Menangani autentikasi user.
 *
 * Menggunakan rate limiting untuk proteksi brute-force.
 * Setelah login berhasil, redirect berdasarkan role user.
 */
class LoginController extends Controller
{
    /**
     * Tampilkan halaman login.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => true,
            'status'           => session('status'),
        ]);
    }

    /**
     * Proses login: validasi, rate limit, autentikasi, redirect.
     */
    public function store(Request $request): RedirectResponse
    {
        // ─── Validasi input ───────────────────────────────────────────────
        $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required'    => 'Email wajib diisi.',
            'email.email'       => 'Format email tidak valid.',
            'password.required' => 'Password wajib diisi.',
        ]);

        // ─── Rate Limiting (max 5 percobaan per menit) ────────────────────
        $this->checkRateLimit($request);

        // ─── Autentikasi ──────────────────────────────────────────────────
        if (!Auth::attempt(
            $request->only('email', 'password'),
            $request->boolean('remember')
        )) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'email' => __('Email atau password tidak sesuai.'),
            ]);
        }

        // ─── Cek apakah akun aktif ────────────────────────────────────────
        $user = Auth::user();
        if (!$user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'Akun Anda tidak aktif. Hubungi administrator.',
            ]);
        }

        // ─── Bersihkan rate limiter & regenerate session ──────────────────
        RateLimiter::clear($this->throttleKey($request));
        $request->session()->regenerate();

        // ─── Update last login info ────────────────────────────────────────
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // ─── Redirect berdasarkan role ────────────────────────────────────
        return $this->redirectBasedOnRole($user);
    }

    /**
     * Logout user dan hapus session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Tentukan redirect setelah login berdasarkan role user.
     */
    private function redirectBasedOnRole(User $user): RedirectResponse
    {
        // Set team context untuk cek role
        if ($user->company_id) {
            setPermissionsTeamId($user->company_id);
        }

        if ($user->hasRole('super_admin')) {
            return redirect()->route('super-admin.dashboard')
                ->with('success', "Selamat datang, {$user->name}!");
        }

        if ($user->hasRole(['bim_admin', 'bim_viewer', 'government_auditor'])) {
            return redirect()->route('bim.dashboard')
                ->with('success', "Selamat datang, {$user->name}!");
        }

        // Company users (admin, operator, auditor)
        return redirect()->route('company.dashboard')
            ->with('success', "Selamat datang, {$user->name}!");
    }

    /**
     * Cek rate limiting untuk endpoint login.
     */
    private function checkRateLimit(Request $request): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            $seconds = RateLimiter::availableIn($this->throttleKey($request));

            throw ValidationException::withMessages([
                'email' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
            ]);
        }
    }

    /**
     * Generate throttle key unik per email + IP.
     */
    private function throttleKey(Request $request): string
    {
        return Str::transliterate(
            Str::lower($request->string('email')) . '|' . $request->ip()
        );
    }
}