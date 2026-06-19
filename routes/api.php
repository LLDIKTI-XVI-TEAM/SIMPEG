<?php

use App\Http\Controllers\AuditLogController;
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

/*
|--------------------------------------------------------------------------
| Audit Logs — admin only (PRD §15.3)
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'keycloak.auth', 'role:super_admin,admin_kepegawaian'])
    ->get('/audit-logs', [AuditLogController::class, 'index'])
    ->name('audit-logs.index');

/*
|--------------------------------------------------------------------------
| Development Test Routes (HAPUS DI PRODUCTION!)
|--------------------------------------------------------------------------
*/
if (app()->environment('local', 'testing')) {
    Route::prefix('test/employees')
        ->name('test.employees.')
        ->group(function (): void {
            Route::post('/', [EmployeeController::class, 'store'])->name('store');
            Route::post('/import', [EmployeeImportController::class, 'store'])->name('import.store');
        });

    Route::get('test/audit-logs', [AuditLogController::class, 'index'])->name('test.audit-logs.index');
}
