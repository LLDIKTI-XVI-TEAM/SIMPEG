<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\HariLiburController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'keycloak.auth', 'role:super_admin,admin_kepegawaian'])
    ->prefix('employees')
    ->name('employees.')
    ->group(function (): void {
        Route::post('/', [EmployeeController::class, 'store'])->name('store');
        Route::post('/import', [EmployeeImportController::class, 'store'])->name('import.store');
    });

Route::middleware(['web', 'keycloak.auth', 'role:super_admin'])
    ->prefix('hari-libur')
    ->name('hari-libur.')
    ->group(function (): void {
        Route::get('/', [HariLiburController::class, 'index'])->name('index');
        Route::post('/', [HariLiburController::class, 'store'])->name('store');
        Route::put('/{hariLibur}', [HariLiburController::class, 'update'])->name('update');
        Route::delete('/{hariLibur}', [HariLiburController::class, 'destroy'])->name('destroy');
    });

/*
|--------------------------------------------------------------------------
| Audit Logs — admin only (PRD §15.3)
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'keycloak.auth', 'role:super_admin,admin_kepegawaian'])
    ->get('/audit-logs', [AuditLogController::class, 'index'])
    ->name('audit-logs.index');
