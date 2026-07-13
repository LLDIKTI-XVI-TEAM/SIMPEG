<?php

use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\MyEducationHistoryController;
use App\Http\Controllers\Api\V1\MyFamilyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'keycloak.auth', 'role:pegawai'])
    ->get('/profil-saya', [EmployeeController::class, 'myProfile'])
    ->middleware('permission:employees.read_self')
    ->name('profil-saya.show');

Route::middleware(['web', 'keycloak.auth'])
    ->get('/profil-saya/saldo-cuti', [LeaveBalanceController::class, 'showMyBalance'])
    ->name('profil-saya.saldo-cuti');

// ============================================================
// Self-service data keluarga — hanya role pegawai.
// employee_id tidak dikirim di request body, selalu di-resolve
// dari sesi login. Otorisasi berlapis: role → FormRequest → controller.
// ============================================================
Route::middleware(['web', 'keycloak.auth', 'role:pegawai'])
    ->prefix('profil-saya/keluarga')
    ->name('profil-saya.keluarga.')
    ->group(function (): void {
        Route::get('/', [MyFamilyController::class, 'index'])
            ->middleware('permission:employee_families.read')
            ->name('index');

        Route::post('/', [MyFamilyController::class, 'store'])
            ->middleware('permission:employee_families.create')
            ->name('store');

        Route::put('/{family}', [MyFamilyController::class, 'update'])
            ->whereUuid('family')
            ->middleware('permission:employee_families.update')
            ->name('update');

        Route::delete('/{family}', [MyFamilyController::class, 'destroy'])
            ->whereUuid('family')
            ->middleware('permission:employee_families.delete')
            ->name('destroy');
    });

// ============================================================
// Self-service riwayat pendidikan — hanya role pegawai.
// employee_id selalu di-resolve dari sesi login.
// Otorisasi berlapis: role → FormRequest → controller → action.
// ============================================================
Route::middleware(['web', 'keycloak.auth', 'role:pegawai'])
    ->prefix('profil-saya/pendidikan')
    ->name('profil-saya.pendidikan.')
    ->group(function (): void {
        Route::get('/', [MyEducationHistoryController::class, 'index'])
            ->middleware('permission:employee_histories.read')
            ->name('index');

        Route::post('/', [MyEducationHistoryController::class, 'store'])
            ->middleware('permission:employee_histories.create')
            ->name('store');

        Route::put('/{education}', [MyEducationHistoryController::class, 'update'])
            ->whereUuid('education')
            ->middleware('permission:employee_histories.create')
            ->name('update');

        Route::delete('/{education}', [MyEducationHistoryController::class, 'destroy'])
            ->whereUuid('education')
            ->middleware('permission:employee_histories.create')
            ->name('destroy');
    });
