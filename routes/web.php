<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CutiConfigController;
use App\Http\Controllers\Admin\CutiController;
use App\Http\Controllers\Admin\CutiEmployeeLookupController;
use App\Http\Controllers\Admin\CutiReportController;
use App\Http\Controllers\Admin\DokumenController;
use App\Http\Controllers\Admin\EmployeeImportController;
use App\Http\Controllers\Admin\EwsConfigController;
use App\Http\Controllers\Admin\EwsController;
use App\Http\Controllers\Admin\GlobalSearchController;
use App\Http\Controllers\Admin\HariLiburController;
use App\Http\Controllers\Admin\KepalaBagianDashboardController;
use App\Http\Controllers\Admin\KepalaBagianEmployeeController;
use App\Http\Controllers\Admin\KepalaBagianEwsController;
use App\Http\Controllers\Admin\KepalaBagianLeaveController;
use App\Http\Controllers\Admin\KepalaBagianLeaveDecisionController;
use App\Http\Controllers\Admin\KepalaLembagaSupportingDocumentController;
use App\Http\Controllers\Admin\LaporanController;
use App\Http\Controllers\Admin\LeaveBalanceController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\PegawaiController;
use App\Http\Controllers\Admin\PimpinanDashboardController;
use App\Http\Controllers\Admin\PimpinanEmployeeController;
use App\Http\Controllers\Admin\PimpinanEwsController;
use App\Http\Controllers\Admin\PimpinanLeaveController;
use App\Http\Controllers\Admin\PimpinanLeaveDecisionController;
use App\Http\Controllers\Admin\PimpinanLeaveDocumentController;
use App\Http\Controllers\Admin\PimpinanReportController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\RbacController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserMappingController;
use App\Http\Controllers\Auth\KeycloakAuthController;
use App\Http\Controllers\Cuti\VerifyLeaveProofController;
use App\Http\Controllers\DashboardController;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::get('/login', [KeycloakAuthController::class, 'redirectToKeycloak'])->name('login');
Route::get('/login/keycloak', [KeycloakAuthController::class, 'redirectToKeycloak'])->name('auth.keycloak.redirect');
Route::get('/auth/keycloak/callback', [KeycloakAuthController::class, 'handleCallback'])->name('auth.keycloak.callback');
Route::post('/logout', [KeycloakAuthController::class, 'logout'])->name('logout');
Route::get('/logout', [KeycloakAuthController::class, 'logout'])->name('logout.get');
Route::get('/cuti/verifikasi/{token}', VerifyLeaveProofController::class)
    ->middleware('throttle:60,1')
    ->name('cuti.verify');

if (app()->environment(['local', 'testing'])) {
    Route::get('/dev-login', [KeycloakAuthController::class, 'defaultDemoLogin']);
    Route::post('/dev-login', [KeycloakAuthController::class, 'demoLogin'])->name('dev-login');

    Route::get('/set-super-admin', function () {
        $user = auth()->user();
        if ($user) {
            $user->role = 'super_admin';
            $user->save();
            session(['active_role' => 'super_admin']);

            return redirect()->route('dashboard')->with('success', 'Role Anda telah diubah menjadi super_admin');
        }

        return 'Silakan login terlebih dahulu';
    });

    Route::get('/map-dummy-employee', function () {
        $user = auth()->user();
        if ($user) {
            $employee = Employee::first();
            if (! $employee) {
                $employee = Employee::create([
                    'nip' => '198001012005011001',
                    'nama' => $user->name,
                    'status' => 'aktif',
                ]);
            }
            $user->employee_id = $employee->id;
            $user->save();

            return redirect()->route('profil')->with('success', 'Akun Anda berhasil dipetakan ke data pegawai.');
        }

        return 'Silakan login terlebih dahulu';
    });

    Route::get('/debug-permissions', function () {
        $permission = Permission::firstOrCreate(['name' => 'employee_families.create'], ['module' => 'employee_families', 'description' => 'Membuat data keluarga pegawai']);
        $role = Role::where('name', 'super_admin')->first();
        if ($role) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);

            return 'Permission synced to super_admin';
        }

        return 'Role super_admin not found';
    });
}

Route::middleware(['keycloak.auth', 'session.timeout', 'role:super_admin,admin_kepegawaian,pimpinan,kepala_bagian,pegawai'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/admin/search', [GlobalSearchController::class, 'search'])
        ->middleware('role:super_admin,admin_kepegawaian')
        ->name('global.search');

    Route::get('/change-role/{role}', function (Request $request, string $role) {
        abort_unless($request->user()?->role === $role, 403, 'Role aktif harus sesuai dengan role akun.');

        session(['active_role' => $role]);

        return back();
    })->whereIn('role', ['super_admin', 'admin_kepegawaian', 'pimpinan', 'kepala_bagian', 'pegawai'])
        ->name('change-role');

    Route::get('/pegawai/import-data', function () {
        return view('admin.pegawai.import');
    })->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.import'])
        ->name('pegawai.import');

    Route::get('/pegawai/import/template/{type}', [EmployeeImportController::class, 'template'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.import'])
        ->name('pegawai.import-template');

    // Import API endpoints (dipanggil via fetch dari blade, butuh session auth)
    Route::post('/api/pegawai/import/upload', [EmployeeImportController::class, 'upload'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.import'])
        ->name('pegawai.import.upload');

    Route::get('/api/pegawai/import/{batchId}/preview', [EmployeeImportController::class, 'preview'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.import'])
        ->name('pegawai.import.preview');

    Route::post('/api/pegawai/import/{batchId}/validate', [EmployeeImportController::class, 'validate'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.import'])
        ->name('pegawai.import.validate');

    Route::post('/api/pegawai/import/{batchId}/execute', [EmployeeImportController::class, 'execute'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.import'])
        ->name('pegawai.import.execute');

    Route::get('/api/pegawai/import/{batchId}/status', [EmployeeImportController::class, 'status'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.import'])
        ->name('pegawai.import.status');

    Route::get('/ews', [EwsController::class, 'index'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('ews');
    Route::get('/dashboard/ews-saya', [EwsController::class, 'myAlerts'])
        ->middleware(['role:pegawai'])
        ->name('ews.saya');
    Route::match(['post', 'patch'], '/ews/{alert}/followup', [EwsController::class, 'updateFollowup'])
        ->whereUuid('alert')
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('ews.followup.update');

    Route::get('/laporan-export', function () {
        return view('dummy', ['title' => 'Laporan / Export']);
    })->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('laporan');

    Route::get('/user-management', [UserMappingController::class, 'index'])
        ->middleware(['role:super_admin'])
        ->name('user-management');
    Route::post('/user-management/update', [UserMappingController::class, 'update'])
        ->middleware(['role:super_admin'])
        ->name('user-management.update');

    Route::get('/rbac', [RbacController::class, 'index'])
        ->middleware(['role:super_admin'])
        ->name('rbac');
    Route::post('/rbac/update', [RbacController::class, 'update'])
        ->middleware(['role:super_admin'])
        ->name('rbac.update');

    Route::get('/data-master', function () {
        return view('admin.data-master.index');
    })->middleware(['role:super_admin'])
        ->name('data-master');

    Route::get('/pegawai/nonaktif-list', [PegawaiController::class, 'inactive'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.restore'])
        ->name('data-nonaktif');

    Route::get('/pegawai/data-backup', [PegawaiController::class, 'backup'])
        ->middleware(['role:super_admin'])
        ->name('data-backup');

    Route::get('/cuti/rekap', [CutiController::class, 'rekap'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('cuti.rekap');
    Route::get('/cuti/pegawai/cari', CutiEmployeeLookupController::class)
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan', 'throttle:60,1'])
        ->name('cuti.employee-lookup');
    Route::get('/cuti/laporan', [CutiReportController::class, 'preview'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('cuti.laporan');
    Route::get('/cuti/laporan/pdf', [CutiReportController::class, 'pdf'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('cuti.laporan.pdf');
    Route::get('/cuti/laporan/excel', [CutiReportController::class, 'excel'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('cuti.laporan.excel');

    Route::middleware(['role:super_admin,admin_kepegawaian', 'permission:cuti.kepala_lembaga_documents.manage'])
        ->prefix('cuti/dokumen-kepala-lembaga')
        ->name('cuti.dokumen-kepala-lembaga.')
        ->group(function (): void {
            Route::get('/', [KepalaLembagaSupportingDocumentController::class, 'index'])->name('index');
            Route::post('/{employee}', [KepalaLembagaSupportingDocumentController::class, 'store'])
                ->whereUuid('employee')
                ->name('store');
            Route::get('/{document}/view', [KepalaLembagaSupportingDocumentController::class, 'view'])
                ->whereUuid('document')
                ->name('view');
            Route::get('/{document}/download', [KepalaLembagaSupportingDocumentController::class, 'download'])
                ->whereUuid('document')
                ->name('download');
            Route::delete('/{document}', [KepalaLembagaSupportingDocumentController::class, 'destroy'])
                ->whereUuid('document')
                ->name('destroy');
        });

    Route::get('/konfigurasi', [EwsConfigController::class, 'index'])
        ->middleware(['role:super_admin'])
        ->name('ews.config');
    Route::post('/konfigurasi/update', [EwsConfigController::class, 'update'])
        ->middleware(['role:super_admin'])
        ->name('ews.config.update');

    Route::get('/laporan/export-pegawai', function () {
        $employees = Employee::with(['jenisPegawai:id,nama', 'statusPegawai:id,nama'])
            ->orderBy('nama_lengkap')
            ->get();

        $pegawai = $employees->map(function ($emp) {
            return [
                'id' => $emp->id,
                'nama' => $emp->nama_lengkap,
                'nip' => $emp->nip,
                'unit' => $emp->unitKerja?->nama ?? '-',
                'golongan' => $emp->golongan_terakhir ?? '-',
                'jabatan' => $emp->jabatan_terakhir ?? '-',
                'jenis' => $emp->jenisPegawai?->nama ?? '-',
                'status' => $emp->statusPegawai?->nama ?? $emp->status_aktif ?? '-',
                'email' => $emp->email_pribadi ?? '-',
                'no_hp' => $emp->no_hp ?? '-',
                'tanggal_lahir' => $emp->tanggal_lahir?->translatedFormat('d F Y') ?? '-',
                'tanggal_pensiun' => $emp->tanggal_pensiun?->translatedFormat('d F Y') ?? '-',
                'pendidikan' => $emp->pendidikan_terakhir ?? '-',
                'pangkat' => $emp->pangkat_terakhir ?? '-',
            ];
        })->toArray();

        $unitList = collect($pegawai)->pluck('unit')->unique()->filter(fn ($v) => $v !== '-')->sort()->values();
        $golonganList = collect($pegawai)->pluck('golongan')->unique()->filter(fn ($v) => $v !== '-')->sort()->values();
        $jenisList = collect($pegawai)->pluck('jenis')->unique()->filter(fn ($v) => $v !== '-')->sort()->values();
        $statusList = collect($pegawai)->pluck('status')->unique()->filter(fn ($v) => $v !== '-')->sort()->values();

        return view('admin.laporan.export-pegawai', [
            'pegawai' => $pegawai,
            'unitList' => $unitList,
            'golonganList' => $golonganList,
            'jenisList' => $jenisList,
            'statusList' => $statusList,
            'title' => 'Laporan - Export Pegawai',
        ]);
    })->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('laporan.pegawai');

    Route::get('/laporan/export-pegawai/excel', [LaporanController::class, 'exportPegawaiExcel'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('laporan.pegawai.excel');

    Route::post('/laporan/export-pegawai/custom', [LaporanController::class, 'exportPegawaiCustom'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('laporan.pegawai.custom');
    Route::get('/pegawai', [PegawaiController::class, 'index'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.read'])
        ->name('data-pegawai');
    Route::get('/pegawai/create', [PegawaiController::class, 'create'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.create'])
        ->name('pegawai.create');
    Route::post('/pegawai', [PegawaiController::class, 'store'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.create'])
        ->name('pegawai.store');
    Route::get('/pegawai/{id}', [PegawaiController::class, 'show'])
        ->whereUuid('id')
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.read'])
        ->name('pegawai.show');
    Route::get('/pegawai/{id}/edit', [PegawaiController::class, 'edit'])
        ->whereUuid('id')
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.update'])
        ->name('pegawai.edit');
    Route::post('/pegawai/{id}', [PegawaiController::class, 'update'])
        ->whereUuid('id')
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.update'])
        ->name('pegawai.update');
    Route::post('/pegawai/{id}/kinerja-baik', [PegawaiController::class, 'updatePerformanceFlag'])
        ->whereUuid('id')
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.update'])
        ->name('pegawai.kinerja.update');
    Route::post('/pegawai/{id}/satyalancana-eligibility', [PegawaiController::class, 'updateSatyalancanaEligibility'])
        ->whereUuid('id')
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.update'])
        ->name('pegawai.satyalancana.update');
    Route::post('/pegawai/bulk-destroy', [PegawaiController::class, 'bulkDestroy'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.deactivate'])
        ->name('pegawai.bulkDestroy');
    Route::post('/pegawai/{id}/delete', [PegawaiController::class, 'destroy'])
        ->whereUuid('id')
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.deactivate'])
        ->name('pegawai.destroy');
    Route::post('/pegawai/{id}/restore', [PegawaiController::class, 'restore'])
        ->whereUuid('id')
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.restore'])
        ->name('pegawai.restore');
    Route::post('/pegawai/bulk-restore', [PegawaiController::class, 'bulkRestore'])
        ->middleware(['role:super_admin', 'permission:employees.restore'])
        ->name('pegawai.bulkRestore');
    Route::post('/pegawai/{id}/riwayat', [PegawaiController::class, 'storeRiwayat'])
        ->whereUuid('id')
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.update'])
        ->name('pegawai.riwayat.store');
    Route::post('/pegawai/{id}/assign-atasan', [PegawaiController::class, 'assignAtasan'])
        ->whereUuid('id')
        ->middleware(['role:super_admin', 'permission:employees.update'])
        ->name('pegawai.assign-atasan');

    Route::get('/dashboard/cuti/saldo', [LeaveBalanceController::class, 'showMyBalanceWeb'])
        ->name('cuti.saldo');
    Route::post('/dashboard/cuti/saldo/{employee}/opening-balance', [LeaveBalanceController::class, 'storeOpeningBalance'])
        ->whereUuid('employee')
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:cuti.balance.adjust'])
        ->name('cuti.saldo.opening-balance');
    Route::post('/dashboard/cuti/saldo/{employee}/adjust', [LeaveBalanceController::class, 'adjust'])
        ->whereUuid('employee')
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:cuti.balance.adjust'])
        ->name('cuti.saldo.adjust');

    Route::get('/pegawai/legacy', function () {
        return redirect()->route('data-pegawai');
    })->name('pegawai.index');

    Route::get('/hari-libur', [HariLiburController::class, 'index'])
        ->middleware(['role:super_admin', 'permission:hari_libur.read'])
        ->name('hari-libur');
    Route::post('/hari-libur', [HariLiburController::class, 'store'])
        ->middleware(['role:super_admin', 'permission:hari_libur.create'])
        ->name('hari-libur.store');
    Route::get('/hari-libur/{id}/edit', [HariLiburController::class, 'edit'])
        ->middleware(['role:super_admin', 'permission:hari_libur.update'])
        ->name('hari-libur.edit');
    Route::post('/hari-libur/{id}', [HariLiburController::class, 'update'])
        ->middleware(['role:super_admin', 'permission:hari_libur.update'])
        ->name('hari-libur.update');
    Route::post('/hari-libur/{id}/delete', [HariLiburController::class, 'destroy'])
        ->middleware(['role:super_admin', 'permission:hari_libur.delete'])
        ->name('hari-libur.destroy');

    Route::get('/hari-libur/legacy', function () {
        return redirect()->route('hari-libur');
    })->name('hari-libur.index');

    Route::get('/dashboard/cuti', [CutiController::class, 'index'])->name('cuti');
    Route::get('/dashboard/cuti/create', [CutiController::class, 'create'])
        ->middleware('permission:cuti.create')
        ->name('cuti.create');
    Route::post('/dashboard/cuti', [CutiController::class, 'store'])
        ->middleware('permission:cuti.create')
        ->name('cuti.store');
    Route::patch('/dashboard/cuti/{leaveRequest}/resubmit', [CutiController::class, 'resubmit'])
        ->middleware('permission:cuti.create')
        ->name('cuti.resubmit')
        ->whereUuid('leaveRequest');
    Route::get('/dashboard/cuti/{leaveRequest}/formulir-pdf', [CutiController::class, 'formulirPdf'])
        ->name('cuti.formulir-pdf')
        ->whereUuid('leaveRequest');
    // Antrean dan tindakan approval cuti digerbang ganda: role allowlist sebagai pagar kasar
    // dan permission level-aksi; kelayakan approver per-tahap (person-based) ditegakkan di service.
    Route::get('/cuti/approval', [CutiController::class, 'approval'])
        ->middleware(['role:super_admin,pimpinan,kepala_bagian,admin_kepegawaian,pegawai'])
        ->name('cuti.approval');
    Route::post('/cuti/{id}/approve', [CutiController::class, 'approve'])
        ->middleware(['role:super_admin,pimpinan,kepala_bagian,admin_kepegawaian,pegawai'])
        ->name('cuti.approve')
        ->whereUuid('id');
    Route::post('/cuti/{id}/postpone', [CutiController::class, 'postpone'])
        ->middleware(['role:super_admin,pimpinan,kepala_bagian,admin_kepegawaian,pegawai'])
        ->name('cuti.postpone')
        ->whereUuid('id');
    Route::post('/cuti/{id}/request-changes', [CutiController::class, 'requestChanges'])
        ->middleware(['role:super_admin,pimpinan,kepala_bagian,admin_kepegawaian,pegawai'])
        ->name('cuti.request-changes')
        ->whereUuid('id');
    Route::post('/cuti/{id}/reject', [CutiController::class, 'reject'])
        ->middleware(['role:super_admin,pimpinan,kepala_bagian,admin_kepegawaian,pegawai'])
        ->name('cuti.reject')
        ->whereUuid('id');
    Route::get('/dashboard/cuti/{id}', [CutiController::class, 'show'])
        ->name('cuti.show')
        ->whereUuid('id');

    // Konfigurasi rantai approval cuti bersifat pengaturan sistem, jadi digerbang ganda:
    // role:super_admin sebagai pagar kasar dan permission:cuti.configure sebagai gerbang aksi.
    Route::get('/cuti/konfigurasi-approval', [CutiConfigController::class, 'index'])
        ->middleware(['role:super_admin', 'permission:cuti.configure'])
        ->name('cuti.config');
    Route::post('/cuti/konfigurasi-approval', [CutiConfigController::class, 'update'])
        ->middleware(['role:super_admin', 'permission:cuti.configure'])
        ->name('cuti.config.update');
    Route::post('/cuti/konfigurasi-approval/backfill', [CutiConfigController::class, 'backfill'])
        ->middleware(['role:super_admin', 'permission:cuti.configure_chain'])
        ->name('cuti.config.backfill');
    Route::post('/cuti/konfigurasi-approval/pybmc-global', [CutiConfigController::class, 'updateGlobalPybmc'])
        ->middleware(['role:super_admin', 'permission:cuti.configure_chain'])
        ->name('cuti.config.pybmc-global');
    Route::post('/cuti/konfigurasi-approval/pegawai/{employee}', [CutiConfigController::class, 'storeEmployeeChain'])
        ->middleware(['role:super_admin', 'permission:cuti.configure_chain'])
        ->name('cuti.config.employee-chain.store')
        ->whereUuid('employee');

    Route::get('/dashboard/cuti/legacy', function () {
        return redirect()->route('cuti');
    })->name('cuti.index');

    Route::get('/cuti', function () {
        return redirect()->route('cuti');
    });

    Route::get('/dashboard/dokumen', [DokumenController::class, 'index'])
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('dokumen');
    Route::post('/dashboard/dokumen/upload', [DokumenController::class, 'store'])
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('dokumen.store');
    Route::post('/dashboard/dokumen/{id}', [DokumenController::class, 'update'])
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('dokumen.update')
        ->whereUuid('id');
    Route::get('/dashboard/dokumen/{id}', [DokumenController::class, 'show'])
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('dokumen.show')
        ->whereUuid('id');
    Route::get('/dashboard/dokumen/{id}/download', [DokumenController::class, 'download'])
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('dokumen.download')
        ->whereUuid('id');
    Route::delete('/dashboard/dokumen/{id}', [DokumenController::class, 'destroy'])
        ->middleware(['role:super_admin'])
        ->name('dokumen.destroy')
        ->whereUuid('id');
    Route::get('/dashboard/dokumen/{id}/check-impact', [DokumenController::class, 'checkImpact'])
        ->middleware(['role:super_admin'])
        ->name('dokumen.check-impact')
        ->whereUuid('id');

    Route::get('/dashboard/dokumen/legacy', function () {
        return redirect()->route('dokumen');
    })->name('dokumen.index');

    Route::get('/dokumen', function () {
        return redirect()->route('dokumen');
    });

    Route::get('/dashboard/audit', [AuditController::class, 'index'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:audit_logs.read'])
        ->name('audit-log');
    Route::get('/dashboard/audit/{id}', [AuditController::class, 'show'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:audit_logs.read'])
        ->name('audit-log.show')
        ->whereUuid('id');

    Route::get('/dashboard/audit/legacy', function () {
        return redirect()->route('audit-log');
    })->name('audit.index');

    Route::get('/audit', function () {
        return redirect()->route('audit-log');
    });

    Route::get('/dashboard/profil', [ProfileController::class, 'index'])->name('profil');
    Route::post('/dashboard/profil/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::get('/dashboard/pengaturan', [SettingsController::class, 'index'])
        ->middleware(['role:super_admin'])
        ->name('pengaturan');
    Route::post('/dashboard/pengaturan', [SettingsController::class, 'update'])
        ->middleware(['role:super_admin'])
        ->name('settings.update');

    Route::get('/dashboard/pengaturan/legacy', function () {
        return redirect()->route('pengaturan');
    })->name('settings.index');

    Route::get('/pengaturan', function () {
        return redirect()->route('pengaturan');
    });

    Route::get('/dashboard/Pengaturan', function () {
        return redirect()->route('pengaturan');
    });

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->middleware('permission:notifications.read')
        ->name('notifications.index');

    Route::get('/pegawai/export', [PegawaiController::class, 'export'])
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('pegawai.export');

    // =========================================================================
    // ROUTES PIMPINAN
    // =========================================================================
    Route::middleware(['role:pimpinan'])
        ->prefix('pimpinan')
        ->name('pimpinan.')
        ->group(function () {
            Route::get('/dashboard', [PimpinanDashboardController::class, 'index'])->name('dashboard');

            Route::get('/pegawai', [PimpinanEmployeeController::class, 'index'])->name('pegawai.index');
            Route::get('/pegawai/{employee}', [PimpinanEmployeeController::class, 'show'])
                ->whereUuid('employee')
                ->name('pegawai.show');

            Route::get('/cuti', [PimpinanLeaveController::class, 'index'])->name('cuti.index');
            Route::get('/cuti/{leave}', [PimpinanLeaveController::class, 'show'])
                ->whereUuid('leave')
                ->name('cuti.show');
            Route::post('/cuti/{leave}/decision', [PimpinanLeaveDecisionController::class, 'store'])
                ->whereUuid('leave')
                ->name('cuti.decision');
            Route::get('/cuti/{leave}/dokumen', [PimpinanLeaveDocumentController::class, 'show'])
                ->whereUuid('leave')
                ->name('cuti.document.show');
            Route::get('/cuti/{leave}/dokumen/download', [PimpinanLeaveDocumentController::class, 'download'])
                ->whereUuid('leave')
                ->name('cuti.document.download');
            Route::get('/cuti/{leave}/lampiran', [PimpinanLeaveDocumentController::class, 'downloadAttachment'])
                ->whereUuid('leave')
                ->name('cuti.attachment.download');

            Route::get('/ews', [PimpinanEwsController::class, 'index'])->name('ews.index');

            Route::get('/laporan', [PimpinanReportController::class, 'index'])->name('laporan.index');
            Route::get('/laporan/pegawai', [PimpinanReportController::class, 'employees'])->name('laporan.pegawai');
            Route::get('/laporan/pegawai/custom', [PimpinanReportController::class, 'customEmployees'])->name('laporan.pegawai.custom');
            Route::get('/laporan/cuti', [PimpinanReportController::class, 'leaves'])->name('laporan.cuti');
            Route::get('/laporan/cuti/excel', [PimpinanReportController::class, 'exportLeaves'])->name('laporan.cuti.excel');
            Route::get('/laporan/kepangkatan', [PimpinanReportController::class, 'rankHistories'])->name('laporan.kepangkatan');
            Route::get('/laporan/kepangkatan/excel', [PimpinanReportController::class, 'exportRankHistoriesExcel'])->name('laporan.kepangkatan.excel');
            Route::get('/laporan/kepangkatan/pdf', [PimpinanReportController::class, 'exportRankHistoriesPdf'])->name('laporan.kepangkatan.pdf');
        });

    Route::middleware(['role:kepala_bagian'])
        ->prefix('kepala-bagian')
        ->name('kepala-bagian.')
        ->group(function (): void {
            Route::get('/dashboard', [KepalaBagianDashboardController::class, 'index'])->name('dashboard');

            Route::get('/bawahan', [KepalaBagianEmployeeController::class, 'index'])->name('bawahan.index');
            Route::get('/bawahan/{employee}', [KepalaBagianEmployeeController::class, 'show'])
                ->whereUuid('employee')
                ->name('bawahan.show');

            Route::get('/cuti', [KepalaBagianLeaveController::class, 'index'])->name('cuti.index');
            Route::get('/cuti/{leave}', [KepalaBagianLeaveController::class, 'show'])
                ->whereUuid('leave')
                ->name('cuti.show');
            Route::post('/cuti/{leave}/keputusan', [KepalaBagianLeaveDecisionController::class, 'store'])
                ->whereUuid('leave')
                ->name('cuti.decision');
            Route::get('/cuti/{leave}/lampiran', [KepalaBagianLeaveController::class, 'downloadAttachment'])
                ->whereUuid('leave')
                ->name('cuti.attachment.download');

            Route::get('/ews', [KepalaBagianEwsController::class, 'index'])->name('ews.index');
        });
});
