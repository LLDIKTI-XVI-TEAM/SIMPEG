<?php

use App\Http\Controllers\Api\V1\CalculateWorkdayController;
use App\Http\Controllers\Api\V1\LeaveBalancePreviewController;
use Illuminate\Support\Facades\Route;

// Endpoint kalkulasi hari kerja dipakai form pengajuan cuti secara realtime (AJAX).
// Otorisasi PATEN pemohon diperiksa FormRequest tanpa allowlist role tambahan.
Route::middleware([
    'web',
    'keycloak.auth',
    'session.timeout',
])
    ->prefix('cuti')
    ->name('cuti.')
    ->group(function (): void {
        Route::get('/calculate-workdays', CalculateWorkdayController::class)
            ->name('calculate-workdays');

        Route::get('/balance-preview', LeaveBalancePreviewController::class)
            ->name('balance-preview');
    });
