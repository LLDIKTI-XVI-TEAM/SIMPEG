<?php

use App\Http\Middleware\AuditRoleSimulationUsage;
use App\Http\Middleware\EnsureKeycloakAuthenticated;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SessionTimeoutMessage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'keycloak.auth' => EnsureKeycloakAuthenticated::class,
            'permission' => EnsurePermission::class,
            'role' => EnsureRole::class,
            'session.timeout' => SessionTimeoutMessage::class,
        ]);

        // Catat pemakaian role sementara pada request sukses agar aktor asli dan role
        // efektif tetap dapat ditelusuri tanpa mengandalkan kontrol yang terlihat di UI.
        $middleware->web(append: [
            AuditRoleSimulationUsage::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
