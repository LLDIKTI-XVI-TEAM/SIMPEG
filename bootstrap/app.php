<?php

use App\Http\Middleware\AuditRoleSimulationUsage;
use App\Http\Middleware\EnsureActiveEmployeeAccount;
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

        // Middleware global hanya mengamati route web; kelasnya sendiri membatasi audit
        // pada route yang benar-benar memiliki gate role atau permission internal.
        $middleware->web(append: [
            AuditRoleSimulationUsage::class,
            EnsureActiveEmployeeAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Defense-in-depth: credential write-only tidak boleh masuk old input walaupun
        // validasi kelak gagal di luar FormRequest khusus konfigurasi WhatsApp.
        $exceptions->dontFlash([
            'access_token',
            'refresh_token',
            'channel_integration_id',
        ]);
    })->create();
