<?php

use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Middleware\PreserveNotificationPollingFlash;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'keycloak.auth', 'session.timeout', 'role:super_admin,admin_kepegawaian,pimpinan,kepala_bagian,pegawai'])
    ->prefix('notifikasi')
    ->name('notifikasi.')
    ->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])
            ->middleware(['permission:notifications.read', PreserveNotificationPollingFlash::class])
            ->name('index');
        Route::get('/jumlah-belum-dibaca', [NotificationController::class, 'unreadCount'])
            ->middleware(['permission:notifications.read', PreserveNotificationPollingFlash::class])
            ->name('jumlah-belum-dibaca');
        Route::patch('/tandai-semua-dibaca', [NotificationController::class, 'markAllAsRead'])
            ->middleware('permission:notifications.update')
            ->name('tandai-semua-dibaca');
        Route::patch('/{notificationId}/tandai-dibaca', [NotificationController::class, 'markAsRead'])
            ->middleware('permission:notifications.update')
            ->whereUuid('notificationId')
            ->name('tandai-dibaca');
    });
