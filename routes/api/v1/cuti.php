<?php

use App\Http\Controllers\Api\V1\CalculateWorkdayController;
use App\Http\Controllers\Api\V1\LeaveBalancePreviewController;
use Illuminate\Support\Facades\Route;

// Endpoint kalkulasi hari kerja dipakai form pengajuan cuti secara realtime (AJAX).
// Gerbang ganda: role allowlist Fase 1 + permission cuti.create sebagai gate level aksi.
Route::middleware([
    'web',
    'keycloak.auth',
    'role:super_admin,admin_kepegawaian,pimpinan,kepala_bagian,pegawai',
    'permission:cuti.create',
])
    ->prefix('cuti')
    ->name('cuti.')
    ->group(function (): void {
        Route::get('/calculate-workdays', CalculateWorkdayController::class)
            ->name('calculate-workdays');

        Route::get('/balance-preview', LeaveBalancePreviewController::class)
            ->name('balance-preview');
    });
