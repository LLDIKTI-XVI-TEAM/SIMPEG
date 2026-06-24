<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DisciplineRecordController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\HariLiburController;
use App\Http\Controllers\KgbHistoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PositionHistoryController;
use App\Http\Controllers\RankHistoryController;
use Illuminate\Support\Facades\Route;

$disableEmployeeApiAuth = app()->environment('local')
    && filter_var(env('SIMPEG_DISABLE_EMPLOYEE_API_AUTH', false), FILTER_VALIDATE_BOOLEAN);

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
        Route::get('/{employee}', [EmployeeController::class, 'show'])
            ->middleware($disableEmployeeApiAuth ? [] : ['permission:employees.read'])
            ->name('show');
    });

Route::middleware(['web', 'keycloak.auth', 'role:pegawai'])
    ->get('/profil-saya', [EmployeeController::class, 'myProfile'])
    ->middleware('permission:employees.read_self')
    ->name('profil-saya.show');

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
| Audit Logs — hanya admin kepegawaian berizin audit
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'keycloak.auth', 'role:super_admin,admin_kepegawaian', 'permission:audit_logs.read'])
    ->get('/audit-log', [AuditLogController::class, 'index'])
    ->name('audit-log.index');

Route::middleware(['web', 'keycloak.auth', 'role:super_admin,admin_kepegawaian,pimpinan,atasan_langsung,pegawai'])
    ->prefix('notifikasi')
    ->name('notifikasi.')
    ->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])
            ->middleware('permission:notifications.read')
            ->name('index');
        Route::get('/jumlah-belum-dibaca', [NotificationController::class, 'unreadCount'])
            ->middleware('permission:notifications.read')
            ->name('jumlah-belum-dibaca');
        Route::patch('/tandai-semua-dibaca', [NotificationController::class, 'markAllAsRead'])
            ->middleware('permission:notifications.update')
            ->name('tandai-semua-dibaca');
        Route::patch('/{notificationId}/tandai-dibaca', [NotificationController::class, 'markAsRead'])
            ->middleware('permission:notifications.update')
            ->whereUuid('notificationId')
            ->name('tandai-dibaca');
    });
