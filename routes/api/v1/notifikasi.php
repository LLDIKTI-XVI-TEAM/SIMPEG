<?php

use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Middleware\EnsureActiveEmployeeAccount;
use App\Http\Middleware\PreserveNotificationPollingFlash;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'keycloak.auth', 'session.timeout'])
    ->prefix('notifikasi')
    ->name('notifikasi.')
    ->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])
            ->middleware(['user.context.account', 'permission:notifications.read', PreserveNotificationPollingFlash::class])
            ->withoutMiddleware(EnsureActiveEmployeeAccount::class)
            ->name('index');
        Route::get('/jumlah-belum-dibaca', [NotificationController::class, 'unreadCount'])
            ->middleware(['user.context.account', 'permission:notifications.read', PreserveNotificationPollingFlash::class])
            ->withoutMiddleware(EnsureActiveEmployeeAccount::class)
            ->name('jumlah-belum-dibaca');
        Route::patch('/tandai-semua-dibaca', [NotificationController::class, 'markAllAsRead'])
            ->middleware(['user.context.account', 'permission:notifications.update'])
            ->withoutMiddleware(EnsureActiveEmployeeAccount::class)
            ->name('tandai-semua-dibaca');
        Route::patch('/{notificationId}/tandai-dibaca', [NotificationController::class, 'markAsRead'])
            ->middleware(['user.context.account', 'permission:notifications.update'])
            ->withoutMiddleware(EnsureActiveEmployeeAccount::class)
            ->whereUuid('notificationId')
            ->name('tandai-dibaca');
    });
