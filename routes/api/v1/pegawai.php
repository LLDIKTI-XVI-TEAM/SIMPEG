<?php

use App\Http\Controllers\Api\V1\AppointmentController;
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
    : ['web', 'keycloak.auth', 'session.timeout', 'role:super_admin,admin_kepegawaian,pimpinan,kepala_bagian,pegawai'];
// Permission menjaga aksi, sedangkan employee.scope mencegah permission mandiri
// dipakai untuk merekam data pegawai lain melalui UUID pada URL.
$adminEmployeeReadMiddleware = static fn (string $permission = 'employees.read'): array => $disableEmployeeApiAuth
    ? []
    : ['permission:'.$permission, 'employee.scope'];
// Permission menjaga aksi; employee.scope menjaga batas record sesuai role/ownership.
// Tidak ada role allowlist agar matriks RBAC menjadi sumber kebenaran mutasi.
$adminEmployeeMutationMiddleware = static fn (string $permission): array => $disableEmployeeApiAuth
    ? []
    : ['permission:'.$permission, 'employee.scope'];
$adminSubModuleMutationMiddleware = static fn (string $permission): array => $disableEmployeeApiAuth
    ? []
    : ['permission:'.$permission, 'employee.scope'];

// Role middleware menjadi pagar kasar area admin pegawai; permission middleware menjadi pagar aksi per route.
// Keduanya dipertahankan sebagai defense-in-depth agar akses admin tidak hanya bergantung pada satu lapis kontrol.
Route::middleware($employeeGroupMiddleware)
    ->prefix('pegawai')
    ->name('pegawai.')
    ->group(function () use ($adminEmployeeMutationMiddleware, $adminSubModuleMutationMiddleware, $adminEmployeeReadMiddleware, $disableEmployeeApiAuth): void {
        Route::get('/', [EmployeeController::class, 'index'])
            // Daftar dipakai oleh halaman pemantauan Pimpinan dan tidak menerima UUID target;
            // pembatasan employee.scope diterapkan pada endpoint yang menunjuk satu rekam pegawai.
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
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])
            ->middleware($adminEmployeeMutationMiddleware('employees.deactivate'))
            ->whereUuid('employee')
            ->name('destroy');

        Route::post('/{employee}/restore', [EmployeeController::class, 'restore'])
            // K-STATUS-04: Admin Kepegawaian ber-permission juga boleh reaktivasi.
            ->middleware($adminEmployeeMutationMiddleware('employees.restore'))
            ->whereUuid('employee')
            ->name('restore');
        Route::get('/{employee}/keluarga', [EmployeeFamilyController::class, 'index'])
            // Payload keluarga Admin memuat NIK; role Pimpinan tetap memakai view yang dimasking.
            ->middleware($adminEmployeeReadMiddleware('employee_families.read'))
            ->whereUuid('employee')
            ->name('keluarga.index');
        Route::post('/{employee}/keluarga', [EmployeeFamilyController::class, 'store'])
            ->middleware($adminSubModuleMutationMiddleware('employee_families.create'))
            ->whereUuid('employee')
            ->name('keluarga.store');
        Route::put('/{employee}/keluarga/{family}', [EmployeeFamilyController::class, 'update'])
            ->middleware($adminSubModuleMutationMiddleware('employee_families.update'))
            ->whereUuid(['employee', 'family'])
            ->name('keluarga.update');
        Route::delete('/{employee}/keluarga/{family}', [EmployeeFamilyController::class, 'destroy'])
            ->middleware($adminSubModuleMutationMiddleware('employee_families.delete'))
            ->whereUuid(['employee', 'family'])
            ->name('keluarga.destroy');

        // Payload JSON Admin memuat relasi mentah dan metadata berkas; Pimpinan memakai surface khusus yang dimasking.
        Route::get('/{employee}', [EmployeeController::class, 'show'])
            ->middleware($adminEmployeeReadMiddleware())
            ->whereUuid('employee')
            ->name('show');
        Route::get('/{employee}/table-row', [EmployeeController::class, 'tableRow'])
            ->middleware($adminEmployeeReadMiddleware())
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
            ->middleware($adminSubModuleMutationMiddleware('discipline_records.create'))
            ->whereUuid('employee')
            ->name('disiplin.store');
        Route::get('/{employee}/arsip-dokumen', [EmployeeDocumentController::class, 'index'])
            ->middleware($adminEmployeeReadMiddleware('employees.read,dokumen_sk.read'))
            ->whereUuid('employee')
            ->name('arsip-dokumen.index');
        Route::post('/{employee}/berkas-lainnya', [EmployeeDocumentController::class, 'storeBerkasLainnya'])
            ->middleware($adminEmployeeMutationMiddleware('employees.update'))
            ->whereUuid('employee')
            ->name('berkas-lainnya.store');
        Route::put('/{employee}/berkas-lainnya/{document}', [EmployeeDocumentController::class, 'updateBerkasLainnya'])
            ->middleware($adminEmployeeMutationMiddleware('employees.update'))
            ->whereUuid(['employee', 'document'])
            ->scopeBindings()
            ->name('berkas-lainnya.update');
        Route::delete('/{employee}/berkas-lainnya/{document}', [EmployeeDocumentController::class, 'destroyBerkasLainnya'])
            ->middleware($adminEmployeeMutationMiddleware('employees.update'))
            ->whereUuid(['employee', 'document'])
            ->scopeBindings()
            ->name('berkas-lainnya.destroy');
        Route::get('/{employee}/status-dokumen', [EmployeeController::class, 'documentStatus'])
            ->middleware($adminEmployeeReadMiddleware('employees.read,dokumen_sk.read'))
            ->whereUuid('employee')
            ->name('status-dokumen');
        Route::get('/{employee}/riwayat-kepangkatan', [RankHistoryController::class, 'index'])
            ->middleware($adminEmployeeReadMiddleware('employee_histories.read'))
            ->whereUuid('employee')
            ->name('riwayat-kepangkatan.index');
        Route::post('/{employee}/riwayat-kepangkatan', [RankHistoryController::class, 'store'])
            ->middleware($adminSubModuleMutationMiddleware('employee_histories.create'))
            ->whereUuid('employee')
            ->name('riwayat-kepangkatan.store');
        Route::post('/{employee}/riwayat-kepangkatan/{rank}/upload-sk', [RankHistoryController::class, 'uploadSk'])
            ->middleware($adminSubModuleMutationMiddleware('employee_histories.update,employee_histories.create'))
            ->whereUuid(['employee', 'rank'])
            ->name('riwayat-kepangkatan.upload-sk');
        Route::get('/{employee}/riwayat-jabatan', [PositionHistoryController::class, 'index'])
            ->middleware($adminEmployeeReadMiddleware('employee_histories.read'))
            ->whereUuid('employee')
            ->name('riwayat-jabatan.index');
        Route::post('/{employee}/riwayat-jabatan', [PositionHistoryController::class, 'store'])
            ->middleware($adminSubModuleMutationMiddleware('employee_histories.create'))
            ->whereUuid('employee')
            ->name('riwayat-jabatan.store');
        Route::post('/{employee}/riwayat-jabatan/{position}/upload-sk', [PositionHistoryController::class, 'uploadSk'])
            ->middleware($adminSubModuleMutationMiddleware('employee_histories.update,employee_histories.create'))
            ->whereUuid(['employee', 'position'])
            ->name('riwayat-jabatan.upload-sk');
        Route::get('/{employee}/riwayat-kgb', [KgbHistoryController::class, 'index'])
            ->middleware($adminEmployeeReadMiddleware('employee_histories.read'))
            ->whereUuid('employee')
            ->name('riwayat-kgb.index');
        Route::post('/{employee}/riwayat-kgb', [KgbHistoryController::class, 'store'])
            ->middleware($adminSubModuleMutationMiddleware('employee_histories.create'))
            ->whereUuid('employee')
            ->name('riwayat-kgb.store');
        Route::post('/{employee}/riwayat-kgb/{kgb}/upload-sk', [KgbHistoryController::class, 'uploadSk'])
            ->middleware($adminSubModuleMutationMiddleware('employee_histories.update,employee_histories.create'))
            ->whereUuid(['employee', 'kgb'])
            ->name('riwayat-kgb.upload-sk');
        Route::get('/{employee}/riwayat-pendidikan', [EducationHistoryController::class, 'index'])
            ->middleware($adminEmployeeReadMiddleware('employee_histories.read'))
            ->whereUuid('employee')
            ->name('riwayat-pendidikan.index');
        Route::post('/{employee}/riwayat-pendidikan', [EducationHistoryController::class, 'store'])
            ->middleware($adminSubModuleMutationMiddleware('employee_histories.create'))
            ->whereUuid('employee')
            ->name('riwayat-pendidikan.store');
        Route::put('/{employee}/riwayat-pendidikan/{education}', [EducationHistoryController::class, 'update'])
            ->middleware($adminSubModuleMutationMiddleware('employee_histories.create'))
            ->whereUuid(['employee', 'education'])
            ->name('riwayat-pendidikan.update');
        Route::delete('/{employee}/riwayat-pendidikan/{education}', [EducationHistoryController::class, 'destroy'])
            ->middleware($adminSubModuleMutationMiddleware('employee_histories.create'))
            ->whereUuid(['employee', 'education'])
            ->name('riwayat-pendidikan.destroy');
        Route::get('/{employee}/pengangkatan', [AppointmentController::class, 'show'])
            ->middleware($adminEmployeeReadMiddleware('employee_histories.read'))
            ->whereUuid('employee')
            ->name('pengangkatan.show');
        Route::post('/{employee}/pengangkatan', [AppointmentController::class, 'save'])
            ->middleware($adminSubModuleMutationMiddleware('employee_histories.create,employee_histories.update,employees.update'))
            ->whereUuid('employee')
            ->name('pengangkatan.save');
        Route::post('/{employee}/pengangkatan/upload-sk', [AppointmentController::class, 'uploadSk'])
            ->middleware($adminSubModuleMutationMiddleware('employee_histories.create,employee_histories.update,employees.update'))
            ->whereUuid('employee')
            ->name('pengangkatan.upload-sk');
        Route::post('/{employee}/assign-atasan', [EmployeeController::class, 'assignSupervisor'])
            ->middleware($adminEmployeeMutationMiddleware('employees.update'))
            ->whereUuid('employee')
            ->name('assign-atasan');
    });
