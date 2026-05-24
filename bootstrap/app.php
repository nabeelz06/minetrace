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

        // ─── Inertia + Asset headers ──────────────────────────────────────
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // ─── Spatie Permission aliases ────────────────────────────────────
        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,

            // Middleware custom untuk set team context sebelum cek role
            'set.team'           => \App\Http\Middleware\SetPermissionsTeamId::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();