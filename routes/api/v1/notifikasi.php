<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'keycloak.auth', 'role:super_admin,admin_kepegawaian,pimpinan,atasan_langsung,pegawai'])
    ->prefix('notifikasi')
    ->name('notifikasi.')
    ->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])
            ->middleware('permission:notifications.read')
            ->name('index');
        Route::get('/jumlah-belum-dibaca', [NotificationController::class, 'unreadCount'])
            ->middleware('permission:notifications.read')
            ->name('jumlah-belum-dibaca');
        Route::patch('/tandai-semua-dibaca', [NotificationController::class, 'markAllAsRead'])
            ->middleware('permission:notifications.update')
            ->name('tandai-semua-dibaca');
        Route::patch('/{notificationId}/tandai-dibaca', [NotificationController::class, 'markAsRead'])
            ->middleware('permission:notifications.update')
            ->whereUuid('notificationId')
            ->name('tandai-dibaca');
    });
