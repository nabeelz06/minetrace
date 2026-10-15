<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:        __DIR__.'/../routes/web.php',
        api:        __DIR__.'/../routes/api.php',
        commands:   __DIR__.'/../routes/console.php',
        health:     '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // CSRF aktif untuk SEMUA rute grup `web`, termasuk rute UI bersesi di /api/v1/company/*
        // (mutasi RFID/CCTV). Endpoint perangkat & token (/api/rfid/*, /api/v1/hardware/*,
        // /api/v1/auth/*) berada di grup `api` tanpa sesi sehingga tidak terkena CSRF.

        $middleware->web(append: [
            \App\Http\Middleware\EnsurePasswordIsChanged::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,

            'roles'              => \App\Http\Middleware\RequireRoles::class,   // API ber-token (Sanctum)
            'set.team'           => \App\Http\Middleware\SetPermissionsTeamId::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Penolakan otorisasi pada endpoint API/JSON → bentuk seragam dengan code stabil + dwibahasa.
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return \App\Support\RfidApi::fail('forbidden', 403);
            }
        });
    })->create();