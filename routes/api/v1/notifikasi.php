<?php

use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Middleware\EnsureActiveEmployeeAccount;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'keycloak.auth', 'session.timeout'])
    ->prefix('notifikasi')
    ->name('notifikasi.')
    ->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])
            ->middleware('permission:notifications.read')
            ->withoutMiddleware(EnsureActiveEmployeeAccount::class)
            ->name('index');
        Route::get('/jumlah-belum-dibaca', [NotificationController::class, 'unreadCount'])
            ->middleware('permission:notifications.read')
            ->withoutMiddleware(EnsureActiveEmployeeAccount::class)
            ->name('jumlah-belum-dibaca');
        Route::patch('/tandai-semua-dibaca', [NotificationController::class, 'markAllAsRead'])
            ->middleware('permission:notifications.update')
            ->withoutMiddleware(EnsureActiveEmployeeAccount::class)
            ->name('tandai-semua-dibaca');
        Route::patch('/{notificationId}/tandai-dibaca', [NotificationController::class, 'markAsRead'])
            ->middleware('permission:notifications.update')
            ->withoutMiddleware(EnsureActiveEmployeeAccount::class)
            ->whereUuid('notificationId')
            ->name('tandai-dibaca');
    });
