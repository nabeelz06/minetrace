<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Ganti password oleh pengguna sendiri (wajib saat force_password_change). */
class ChangePasswordController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Account/ChangePassword', [
            'forced' => (bool) $request->user()->force_password_change,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()->symbols()],
        ]);

        $user = $request->user();
        $user->forceFill([
            'password'              => Hash::make($validated['password']),
            'force_password_change' => false,
        ])->save();

        // Sesi perangkat lain ikut berakhir
        Auth::logoutOtherDevices($validated['password']);
        $user->tokens()->delete();

        Log::info("Password diganti oleh pengguna user_id={$user->id} ip={$request->ip()}");

        return redirect()->route('dashboard.redirect')->with('success', 'Password berhasil diganti.');
    }
}
