<?php

use App\Http\Controllers\DisciplineRecordController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeFamilyController;
use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\KgbHistoryController;
use App\Http\Controllers\PositionHistoryController;
use App\Http\Controllers\RankHistoryController;
use Illuminate\Support\Facades\Route;

$disableEmployeeApiAuth = app()->environment('local')
    && config('services.simpeg.disable_employee_api_auth');

$employeeGroupMiddleware = $disableEmployeeApiAuth
    ? []
    : ['web', 'keycloak.auth', 'role:super_admin,admin_kepegawaian'];

// Role middleware menjadi pagar kasar area admin pegawai; permission middleware menjadi pagar aksi per route.
// Keduanya dipertahankan sebagai defense-in-depth agar akses admin tidak hanya bergantung pada satu lapis kontrol.
Route::middleware($employeeGroupMiddleware)
    ->prefix('pegawai')
    ->name('pegawai.')
    ->group(function () use ($disableEmployeeApiAuth): void {
        Route::get('/', [EmployeeController::class, 'index'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.read'])
            ->name('index');
        Route::post('/', [EmployeeController::class, 'store'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.create'])
            ->name('store');
        Route::post('/import', [EmployeeImportController::class, 'store'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.import'])
            ->name('import.store');
        Route::get('/nonaktif', [EmployeeController::class, 'inactive'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.read'])
            ->name('inactive');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.deactivate'])
            ->whereUuid('employee')
            ->name('destroy');
        Route::post('/{employee}/restore', [EmployeeController::class, 'restore'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.restore'])
            ->whereUuid('employee')
            ->name('restore');
        Route::get('/{employee}/keluarga', [EmployeeFamilyController::class, 'index'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employee_families.read'])
            ->whereUuid('employee')
            ->name('keluarga.index');
        Route::post('/{employee}/keluarga', [EmployeeFamilyController::class, 'store'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employee_families.create'])
            ->whereUuid('employee')
            ->name('keluarga.store');
        Route::put('/{employee}/keluarga/{family}', [EmployeeFamilyController::class, 'update'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employee_families.update'])
            ->whereUuid(['employee', 'family'])
            ->name('keluarga.update');
        Route::delete('/{employee}/keluarga/{family}', [EmployeeFamilyController::class, 'destroy'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employee_families.delete'])
            ->whereUuid(['employee', 'family'])
            ->name('keluarga.destroy');
        Route::get('/{employee}', [EmployeeController::class, 'show'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.read'])
            ->whereUuid('employee')
            ->name('show');
        Route::put('/{employee}', [EmployeeController::class, 'update'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.update'])
            ->whereUuid('employee')
            ->name('update');
        Route::get('/{employee}/disiplin', [DisciplineRecordController::class, 'index'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:discipline_records.read'])
            ->whereUuid('employee')
            ->name('disiplin.index');
        Route::post('/{employee}/disiplin', [DisciplineRecordController::class, 'store'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:discipline_records.create'])
            ->whereUuid('employee')
            ->name('disiplin.store');
        Route::get('/{employee}/riwayat-kepangkatan', [RankHistoryController::class, 'index'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employee_histories.read'])
            ->name('riwayat-kepangkatan.index');
        Route::post('/{employee}/riwayat-kepangkatan', [RankHistoryController::class, 'store'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employee_histories.create'])
            ->name('riwayat-kepangkatan.store');
        Route::get('/{employee}/riwayat-jabatan', [PositionHistoryController::class, 'index'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employee_histories.read'])
            ->name('riwayat-jabatan.index');
        Route::post('/{employee}/riwayat-jabatan', [PositionHistoryController::class, 'store'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employee_histories.create'])
            ->name('riwayat-jabatan.store');
        Route::get('/{employee}/riwayat-kgb', [KgbHistoryController::class, 'index'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employee_histories.read'])
            ->name('riwayat-kgb.index');
        Route::post('/{employee}/riwayat-kgb', [KgbHistoryController::class, 'store'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employee_histories.create'])
            ->name('riwayat-kgb.store');
    });
