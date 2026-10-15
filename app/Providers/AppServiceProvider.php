<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AppServiceProvider — konfigurasi service dan performance optimizations.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     */
    public function register(): void
    {
        // ─── Bind services ke container ───────────────────────────────────
        $this->app->singleton(
            \App\Services\Batch\BatchCodeService::class
        );
        $this->app->singleton(
            \App\Services\Batch\QrCodeService::class
        );
        $this->app->singleton(
            \App\Services\Hardware\RfidService::class
        );
        $this->app->singleton(
            \App\Services\Hardware\HardwareEventService::class
        );
        $this->app->singleton(
            \App\Services\Analytics\CompanyAnalyticsService::class
        );
        $this->app->singleton(
            \App\Services\Analytics\BimAnalyticsService::class
        );
        $this->app->singleton(
            \App\Services\Company\OnboardingService::class
        );

        // ─── Register Telescope khusus untuk environment lokal ────────────
        if ($this->app->environment('local')) {
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        // ─── Disable wrapping JSON resources ──────────────────────────────
        JsonResource::withoutWrapping();

        // ─── Otorisasi RFID (satu sumber; 11 peran dipertahankan, L7) ───────
        //   view    : semua peran perusahaan (auditor hanya melihat)
        //   operate : admin + operator tahap (daftar tag, scan manual, batalkan scan, lepas tag)
        //   manage  : company_admin (reader, mode otorisasi, IP perangkat)
        Gate::define('rfid.view', fn (User $u) => $u->company_id && $u->hasAnyRole([
            'company_admin', 'company_auditor', 'operator_lab_station', 'operator_lab_ltj',
            'operator_wet', 'operator_dry', 'operator_warehouse',
        ]));
        Gate::define('rfid.operate', fn (User $u) => $u->company_id && $u->hasAnyRole([
            'company_admin', 'operator_lab_station', 'operator_lab_ltj',
            'operator_wet', 'operator_dry', 'operator_warehouse',
        ]));
        Gate::define('rfid.manage', fn (User $u) => $u->company_id && $u->hasRole('company_admin'));

        // ─── Rate limit perangkat RFID: per identitas (kunci perangkat > kode perusahaan+IP > IP) ───
        // Perangkat ber-kunci: 300/mnt per reader (banyak reader di satu IP/NAT tidak saling memblokir).
        // Perangkat legacy anonim: 120/mnt per IP (sama dengan perilaku lama).
        RateLimiter::for('rfid-device', function (Request $request) {
            if ($key = $request->header('X-Device-Key')) {
                return Limit::perMinute(300)->by('dk:' . hash('sha256', $key));
            }

            return Limit::perMinute(120)->by('ip:' . $request->ip() . ':' . $request->header('X-Company-Code', '-'));
        });

        // ─── Rate limit verifikasi QR publik: 30 permintaan/menit per IP ───
        RateLimiter::for('verify', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // ─── Query performance monitoring (dev only) ──────────────────────
        if (config('app.debug') && config('app.env') !== 'testing') {
            DB::listen(function ($query) {
                if ($query->time > 1000) { // > 1 detik
                    Log::warning('Slow query detected', [
                        'sql'      => $query->sql,
                        'bindings' => $query->bindings,
                        'time_ms'  => $query->time,
                    ]);
                }
            });
        }

        // ─── Enforce HTTPS di production ──────────────────────────────────
        if (config('app.env') === 'production') {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
