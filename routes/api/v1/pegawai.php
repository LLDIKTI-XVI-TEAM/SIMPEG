<?php

use App\Http\Controllers\Api\V1\DisciplineRecordController;
use App\Http\Controllers\Api\V1\EducationHistoryController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\EmployeeFamilyController;
use App\Http\Controllers\Api\V1\EmployeeImportController;
use App\Http\Controllers\Api\V1\KgbHistoryController;
use App\Http\Controllers\Api\V1\PositionHistoryController;
use App\Http\Controllers\Api\V1\RankHistoryController;
use Illuminate\Support\Facades\Route;

$disableEmployeeApiAuth = app()->environment('local')
    && config('services.simpeg.disable_employee_api_auth');

$employeeGroupMiddleware = $disableEmployeeApiAuth
    ? []
    : ['web', 'keycloak.auth', 'session.timeout', 'role:super_admin,admin_kepegawaian,pimpinan'];
$adminEmployeeReadMiddleware = static fn (string $permission = 'employees.read'): array => $disableEmployeeApiAuth
    ? []
    : ['role:super_admin,admin_kepegawaian', 'permission:'.$permission];
$adminEmployeeMutationMiddleware = static fn (string $permission): array => $disableEmployeeApiAuth
    ? []
    : ['role:super_admin,admin_kepegawaian', 'permission:'.$permission];

// Role middleware menjadi pagar kasar area admin pegawai; permission middleware menjadi pagar aksi per route.
// Keduanya dipertahankan sebagai defense-in-depth agar akses admin tidak hanya bergantung pada satu lapis kontrol.
Route::middleware($employeeGroupMiddleware)
    ->prefix('pegawai')
    ->name('pegawai.')
    ->group(function () use ($adminEmployeeMutationMiddleware, $adminEmployeeReadMiddleware, $disableEmployeeApiAuth): void {
        Route::get('/', [EmployeeController::class, 'index'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.read'])
            ->name('index');
        Route::post('/', [EmployeeController::class, 'store'])
            ->middleware($adminEmployeeMutationMiddleware('employees.create'))
            ->name('store');
        Route::post('/check-identity', [EmployeeController::class, 'checkIdentity'])
            ->middleware($adminEmployeeMutationMiddleware('employees.create'))
            ->name('check-identity');
        Route::post('/import', [EmployeeImportController::class, 'store'])
            ->middleware($adminEmployeeMutationMiddleware('employees.import'))
            ->name('import.store');
        Route::get('/nonaktif', [EmployeeController::class, 'inactive'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.read'])
            ->name('inactive');

        // Gate API Data Backup harus sama dengan halaman webnya; sebelumnya hanya super_admin
        // sehingga search/pagination/refresh oleh Admin Kepegawaian berakhir 403 meski restore diizinkan.
        Route::get('/backup', [EmployeeController::class, 'backup'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.restore', 'role:super_admin,admin_kepegawaian'])
            ->name('backup');

        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])
            ->middleware($adminEmployeeMutationMiddleware('employees.deactivate'))
            ->whereUuid('employee')
            ->name('destroy');

        Route::post('/{employee}/restore', [EmployeeController::class, 'restore'])
            ->middleware($adminEmployeeMutationMiddleware('employees.restore'))
            ->whereUuid('employee')
            ->name('restore');
        Route::get('/{employee}/keluarga', [EmployeeFamilyController::class, 'index'])
            // Payload keluarga Admin memuat NIK; role Pimpinan tetap memakai view yang dimasking.
            ->middleware($adminEmployeeReadMiddleware('employee_families.read'))
            ->whereUuid('employee')
            ->name('keluarga.index');
        Route::post('/{employee}/keluarga', [EmployeeFamilyController::class, 'store'])
            ->middleware($adminEmployeeMutationMiddleware('employee_families.create'))
            ->whereUuid('employee')
            ->name('keluarga.store');
        Route::put('/{employee}/keluarga/{family}', [EmployeeFamilyController::class, 'update'])
            ->middleware($adminEmployeeMutationMiddleware('employee_families.update'))
            ->whereUuid(['employee', 'family'])
            ->name('keluarga.update');
        Route::delete('/{employee}/keluarga/{family}', [EmployeeFamilyController::class, 'destroy'])
            ->middleware($adminEmployeeMutationMiddleware('employee_families.delete'))
            ->whereUuid(['employee', 'family'])
            ->name('keluarga.destroy');

        // Payload JSON Admin memuat relasi mentah dan metadata berkas; Pimpinan memakai surface khusus yang dimasking.
        Route::get('/{employee}', [EmployeeController::class, 'show'])
            ->middleware($adminEmployeeReadMiddleware())
            ->whereUuid('employee')
            ->name('show');
        Route::get('/{employee}/table-row', [EmployeeController::class, 'tableRow'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.read'])
            ->whereUuid('employee')
            ->name('table-row');
        Route::put('/{employee}', [EmployeeController::class, 'update'])
            ->middleware($adminEmployeeMutationMiddleware('employees.update'))
            ->whereUuid('employee')
            ->name('update');
        Route::get('/{employee}/disiplin', [DisciplineRecordController::class, 'index'])
            ->middleware($adminEmployeeReadMiddleware('discipline_records.read'))
            ->whereUuid('employee')
            ->name('disiplin.index');
        Route::post('/{employee}/disiplin', [DisciplineRecordController::class, 'store'])
            ->middleware($adminEmployeeMutationMiddleware('discipline_records.create'))
            ->whereUuid('employee')
            ->name('disiplin.store');
        Route::get('/{employee}/arsip-dokumen', [EmployeeDocumentController::class, 'index'])
            ->middleware($adminEmployeeReadMiddleware())
            ->whereUuid('employee')
            ->name('arsip-dokumen.index');
        Route::post('/{employee}/berkas-lainnya', [EmployeeDocumentController::class, 'storeBerkasLainnya'])
            ->middleware($adminEmployeeMutationMiddleware('employees.update'))
            ->whereUuid('employee')
            ->name('berkas-lainnya.store');
        Route::get('/{employee}/status-dokumen', [EmployeeController::class, 'documentStatus'])
            ->middleware($adminEmployeeReadMiddleware())
            ->whereUuid('employee')
            ->name('status-dokumen');
        Route::get('/{employee}/riwayat-kepangkatan', [RankHistoryController::class, 'index'])
            ->middleware($adminEmployeeReadMiddleware('employee_histories.read'))
            ->whereUuid('employee')
            ->name('riwayat-kepangkatan.index');
        Route::post('/{employee}/riwayat-kepangkatan', [RankHistoryController::class, 'store'])
            ->middleware($adminEmployeeMutationMiddleware('employee_histories.create'))
            ->whereUuid('employee')
            ->name('riwayat-kepangkatan.store');
        Route::get('/{employee}/riwayat-jabatan', [PositionHistoryController::class, 'index'])
            ->middleware($adminEmployeeReadMiddleware('employee_histories.read'))
            ->whereUuid('employee')
            ->name('riwayat-jabatan.index');
        Route::post('/{employee}/riwayat-jabatan', [PositionHistoryController::class, 'store'])
            ->middleware($adminEmployeeMutationMiddleware('employee_histories.create'))
            ->whereUuid('employee')
            ->name('riwayat-jabatan.store');
        Route::get('/{employee}/riwayat-kgb', [KgbHistoryController::class, 'index'])
            ->middleware($adminEmployeeReadMiddleware('employee_histories.read'))
            ->whereUuid('employee')
            ->name('riwayat-kgb.index');
        Route::post('/{employee}/riwayat-kgb', [KgbHistoryController::class, 'store'])
            ->middleware($adminEmployeeMutationMiddleware('employee_histories.create'))
            ->whereUuid('employee')
            ->name('riwayat-kgb.store');
        Route::get('/{employee}/riwayat-pendidikan', [EducationHistoryController::class, 'index'])
            ->middleware($adminEmployeeReadMiddleware('employee_histories.read'))
            ->whereUuid('employee')
            ->name('riwayat-pendidikan.index');
        Route::post('/{employee}/riwayat-pendidikan', [EducationHistoryController::class, 'store'])
            ->middleware($adminEmployeeMutationMiddleware('employee_histories.create'))
            ->whereUuid('employee')
            ->name('riwayat-pendidikan.store');
        Route::put('/{employee}/riwayat-pendidikan/{education}', [EducationHistoryController::class, 'update'])
            ->middleware($adminEmployeeMutationMiddleware('employee_histories.create'))
            ->whereUuid(['employee', 'education'])
            ->name('riwayat-pendidikan.update');
        Route::delete('/{employee}/riwayat-pendidikan/{education}', [EducationHistoryController::class, 'destroy'])
            ->middleware($adminEmployeeMutationMiddleware('employee_histories.create'))
            ->whereUuid(['employee', 'education'])
            ->name('riwayat-pendidikan.destroy');
        Route::post('/{employee}/assign-atasan', [EmployeeController::class, 'assignSupervisor'])
            ->middleware($adminEmployeeMutationMiddleware('employees.update'))
            ->whereUuid('employee')
            ->name('assign-atasan');
    });
