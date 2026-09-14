<?php

use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\MyEducationHistoryController;
use App\Http\Controllers\Api\V1\MyFamilyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'keycloak.auth', 'session.timeout'])
    ->get('/profil-saya', [EmployeeController::class, 'myProfile'])
    ->name('profil-saya.show');

Route::middleware(['web', 'keycloak.auth', 'session.timeout'])
    ->get('/profil-saya/saldo-cuti', [LeaveBalanceController::class, 'showMyBalance'])
    ->name('profil-saya.saldo-cuti');

// ============================================================
// Data keluarga profil bersifat read-only dan employee selalu di-resolve dari sesi login.
// ============================================================
Route::middleware(['web', 'keycloak.auth', 'session.timeout'])
    ->prefix('profil-saya/keluarga')
    ->name('profil-saya.keluarga.')
    ->group(function (): void {
        Route::get('/', [MyFamilyController::class, 'index'])
            ->name('index');
    });

// ============================================================
// Riwayat pendidikan profil bersifat read-only dan employee selalu di-resolve dari sesi login.
// ============================================================
Route::middleware(['web', 'keycloak.auth', 'session.timeout'])
    ->prefix('profil-saya/pendidikan')
    ->name('profil-saya.pendidikan.')
    ->group(function (): void {
        Route::get('/', [MyEducationHistoryController::class, 'index'])
            ->name('index');
    });
