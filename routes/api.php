<?php

use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'keycloak.auth', 'role:super_admin,admin_kepegawaian'])
    ->prefix('employees')
    ->name('employees.')
    ->group(function (): void {
        Route::post('/', [EmployeeController::class, 'store'])->name('store');
        Route::post('/import', [EmployeeImportController::class, 'store'])->name('import.store');
    });
