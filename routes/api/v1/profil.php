<?php

use App\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'keycloak.auth', 'role:pegawai'])
    ->get('/profil-saya', [EmployeeController::class, 'myProfile'])
    ->middleware('permission:employees.read_self')
    ->name('profil-saya.show');
