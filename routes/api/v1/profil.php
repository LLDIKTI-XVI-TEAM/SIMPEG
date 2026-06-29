<?php

use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveBalanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'keycloak.auth', 'role:pegawai'])
    ->get('/profil-saya', [EmployeeController::class, 'myProfile'])
    ->middleware('permission:employees.read_self')
    ->name('profil-saya.show');

Route::middleware(['web', 'keycloak.auth'])
    ->get('/profil-saya/saldo-cuti', [LeaveBalanceController::class, 'showMyBalance'])
    ->name('profil-saya.saldo-cuti');
