<?php

use App\Http\Controllers\Api\V1\CalculateWorkdayController;
use App\Http\Controllers\Api\V1\LeaveBalancePreviewController;
use Illuminate\Support\Facades\Route;

// Endpoint kalkulasi hari kerja dipakai form pengajuan cuti secara realtime (AJAX).
// Pengajuan adalah self-service PATEN; role pemohon membatasi konteks form.
Route::middleware([
    'web',
    'keycloak.auth',
    'session.timeout',
    'role:super_admin,admin_kepegawaian,kepala_bagian,pegawai',
])
    ->prefix('cuti')
    ->name('cuti.')
    ->group(function (): void {
        Route::get('/calculate-workdays', CalculateWorkdayController::class)
            ->name('calculate-workdays');

        Route::get('/balance-preview', LeaveBalancePreviewController::class)
            ->name('balance-preview');
    });
