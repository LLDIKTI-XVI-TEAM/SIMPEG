<?php

use App\Http\Middleware\EnsureKeycloakAuthenticated;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SessionTimeoutMessage;
use App\Models\EwsConfig;
use Illuminate\Console\Scheduling\Schedule;
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
    ->withSchedule(function (Schedule $schedule): void {
        $schedulerTime = EwsConfig::getVal('ews_scheduler_time', '07:00');
        $schedule->command('app:run-ews')
            ->timezone('Asia/Makassar')
            ->dailyAt($schedulerTime);

        // Purge permanen pegawai yang sudah ≥ 30 hari di trash — jalan setiap hari pukul 02:00
        $schedule->command('employees:purge-deleted')
            ->timezone('Asia/Makassar')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/purge-deleted-employees.log'));
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'keycloak.auth' => EnsureKeycloakAuthenticated::class,
            'permission' => EnsurePermission::class,
            'role' => EnsureRole::class,
            'session.timeout' => SessionTimeoutMessage::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
