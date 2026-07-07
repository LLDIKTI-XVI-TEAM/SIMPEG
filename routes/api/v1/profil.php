<?php

use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\MyFamilyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'keycloak.auth', 'role:pegawai'])
    ->get('/profil-saya', [EmployeeController::class, 'myProfile'])
    ->middleware('permission:employees.read_self')
    ->name('profil-saya.show');

Route::middleware(['web', 'keycloak.auth'])
    ->get('/profil-saya/saldo-cuti', [LeaveBalanceController::class, 'showMyBalance'])
    ->name('profil-saya.saldo-cuti');

// Endpoint self-service data keluarga pegawai.
// employee_id tidak dikirim di request body — selalu di-resolve dari sesi login di controller.
// Otorisasi "hanya milik sendiri" dijaga berlapis: role middleware, FormRequest::authorize(), dan controller guard.
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
    });
