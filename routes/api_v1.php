<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\HariLiburController;
use Illuminate\Support\Facades\Route;

// Role middleware menjadi pagar kasar Fase 1; permission middleware menjadi pagar aksi per route.
// Keduanya dipertahankan sebagai defense-in-depth agar akses admin tidak hanya bergantung pada satu lapis kontrol.
Route::middleware(['web', 'keycloak.auth', 'role:super_admin,admin_kepegawaian'])
    ->prefix('employees')
    ->name('employees.')
    ->group(function (): void {
        Route::post('/', [EmployeeController::class, 'store'])
            ->middleware('permission:employees.create')
            ->name('store');
        Route::post('/import', [EmployeeImportController::class, 'store'])
            ->middleware('permission:employees.import')
            ->name('import.store');
    });

Route::middleware(['web', 'keycloak.auth', 'role:super_admin'])
    ->prefix('hari-libur')
    ->name('hari-libur.')
    ->group(function (): void {
        Route::get('/', [HariLiburController::class, 'index'])
            ->middleware('permission:hari_libur.read')
            ->name('index');
        Route::post('/', [HariLiburController::class, 'store'])
            ->middleware('permission:hari_libur.create')
            ->name('store');
        Route::put('/{hariLibur}', [HariLiburController::class, 'update'])
            ->middleware('permission:hari_libur.update')
            ->name('update');
        Route::delete('/{hariLibur}', [HariLiburController::class, 'destroy'])
            ->middleware('permission:hari_libur.delete')
            ->name('destroy');
    });

/*
|--------------------------------------------------------------------------
| Audit Logs — admin only (PRD §15.3)
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'keycloak.auth', 'role:super_admin,admin_kepegawaian', 'permission:audit_logs.read'])
    ->get('/audit-logs', [AuditLogController::class, 'index'])
    ->name('audit-logs.index');
