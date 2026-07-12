<?php

namespace App\Providers;

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
