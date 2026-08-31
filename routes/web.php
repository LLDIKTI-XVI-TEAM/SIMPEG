<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CutiConfigController;
use App\Http\Controllers\Admin\CutiController;
use App\Http\Controllers\Admin\CutiEmployeeLookupController;
use App\Http\Controllers\Admin\CutiReportController;
use App\Http\Controllers\Admin\DataMasterController;
use App\Http\Controllers\Admin\DataMasterEselonController;
use App\Http\Controllers\Admin\DataMasterGolonganController;
use App\Http\Controllers\Admin\DataMasterJabatanController;
use App\Http\Controllers\Admin\DataMasterJenisJabatanController;
use App\Http\Controllers\Admin\DataMasterJenjangPendidikanController;
use App\Http\Controllers\Admin\DataMasterProgramStudiController;
use App\Http\Controllers\Admin\DataMasterStatusPegawaiController;
use App\Http\Controllers\Admin\DataMasterUnitKerjaController;
use App\Http\Controllers\Admin\DokumenController;
use App\Http\Controllers\Admin\EmployeeHistoryAttachmentController;
use App\Http\Controllers\Admin\EmployeeImportController;
use App\Http\Controllers\Admin\EmployeeSupervisorLookupController;
use App\Http\Controllers\Admin\EwsConfigController;
use App\Http\Controllers\Admin\EwsController;
use App\Http\Controllers\Admin\GlobalSearchController;
use App\Http\Controllers\Admin\HariLiburController;
use App\Http\Controllers\Admin\KepalaBagianDashboardController;
use App\Http\Controllers\Admin\KepalaBagianEmployeeController;
use App\Http\Controllers\Admin\KepalaBagianEwsController;
use App\Http\Controllers\Admin\KepalaBagianLeaveController;
use App\Http\Controllers\Admin\KepalaBagianLeaveDecisionController;
use App\Http\Controllers\Admin\KepalaBagianSearchController;
use App\Http\Controllers\Admin\KepalaLembagaSupportingDocumentController;
use App\Http\Controllers\Admin\LaporanController;
use App\Http\Controllers\Admin\LeaveBalanceController;
use App\Http\Controllers\Admin\LeaveUsageController;
use App\Http\Controllers\Admin\ManualLeaveUsageController;
use App\Http\Controllers\Admin\NotificationChannelController;
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
use App\Http\Controllers\Admin\SkRequirementController;
use App\Http\Controllers\Admin\SwitchRoleController;
use App\Http\Controllers\Admin\UserMappingController;
use App\Http\Controllers\Auth\InactiveEmployeeAccountController;
use App\Http\Controllers\Auth\KeycloakAuthController;
use App\Http\Controllers\Cuti\VerifyLeaveProofController;
use App\Http\Controllers\DashboardController;
use App\Livewire\Admin\Pegawai\Create;
use App\Livewire\Admin\Pegawai\Edit;
use App\Livewire\Admin\Pegawai\Index;
use App\Livewire\Admin\Pegawai\Show;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
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
Route::get('/status-akun', InactiveEmployeeAccountController::class)
    ->middleware('auth')
    ->name('status-akun');
Route::get('/cuti/verifikasi/{token}', VerifyLeaveProofController::class)
    ->middleware('throttle:60,1')
    ->name('cuti.verify');

if (app()->environment(['local', 'testing'])) {
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

    // Halaman Status Pegawai telah dipindahkan ke aksi per-baris di Data Pegawai.
    // Redirect menjaga bookmark lama tetap membuka titik kerja baru; nama route lama
    // dipertahankan agar caller lama (helper, test, integrasi) tidak memicu RouteNotFoundException.
    Route::redirect('/super-admin/status-pegawai', '/pegawai')
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('super-admin.status-pegawai.index');
    Route::post('/super-admin/status-pegawai', [PegawaiController::class, 'changeStatus'])
        ->middleware(['permission:employees.update'])
        ->name('super-admin.status-pegawai.store');

    Route::get('/admin/search', [GlobalSearchController::class, 'search'])
        ->middleware('role:super_admin,admin_kepegawaian,pimpinan')
        ->name('global.search');

    Route::get('/pegawai/import-data', function () {
        return view('admin.pegawai.import');
    })->middleware(['permission:employees.import'])
        ->name('pegawai.import');

    Route::get('/pegawai/import/template/{type}', [EmployeeImportController::class, 'template'])
        ->middleware(['permission:employees.import'])
        ->name('pegawai.import-template');

    // Import API endpoints (dipanggil via fetch dari blade, butuh session auth)
    Route::post('/api/pegawai/import/upload', [EmployeeImportController::class, 'upload'])
        ->middleware(['permission:employees.import'])
        ->name('pegawai.import.upload');

    Route::get('/api/pegawai/import/{batchId}/preview', [EmployeeImportController::class, 'preview'])
        ->middleware(['permission:employees.import'])
        ->name('pegawai.import.preview');

    Route::post('/api/pegawai/import/{batchId}/validate', [EmployeeImportController::class, 'validate'])
        ->whereUuid('batchId')
        ->middleware(['permission:employees.import'])
        ->name('pegawai.import.validate');

    // Pemetaan kolom disimpan sebagai state batch agar dipakai ulang oleh preview/validasi/eksekusi.
    Route::post('/api/pegawai/import/{batchId}/mapping', [EmployeeImportController::class, 'saveMapping'])
        ->whereUuid('batchId')
        ->middleware(['permission:employees.import'])
        ->name('pegawai.import.mapping');

    Route::post('/api/pegawai/import/{batchId}/execute', [EmployeeImportController::class, 'execute'])
        ->whereUuid('batchId')
        ->middleware(['permission:employees.import'])
        ->name('pegawai.import.execute');

    Route::get('/api/pegawai/import/{batchId}/status', [EmployeeImportController::class, 'status'])
        ->middleware(['permission:employees.import'])
        ->name('pegawai.import.status');

    Route::get('/pegawai/import/{batchId}/laporan', [EmployeeImportController::class, 'report'])
        ->whereUuid('batchId')
        ->middleware(['permission:employees.import'])
        ->name('pegawai.import.report');

    Route::get('/ews', [EwsController::class, 'index'])
        ->middleware(['permission:ews.read'])
        ->name('ews');
    Route::get('/dashboard/ews-saya', [EwsController::class, 'myAlerts'])
        ->middleware(['role:pegawai'])
        ->name('ews.saya');
    Route::match(['post', 'patch'], '/ews/{alert}/followup', [EwsController::class, 'updateFollowup'])
        ->whereUuid('alert')
        ->middleware(['permission:employees.update'])
        ->name('ews.followup.update');

    Route::get('/laporan-export', function () {
        return view('dummy', ['title' => 'Laporan / Export']);
    })->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('laporan');

    Route::get('/user-management', [UserMappingController::class, 'index'])
        ->middleware(['role:super_admin'])
        ->name('user-management');
    Route::get('/user-management/data', [UserMappingController::class, 'data'])
        ->middleware(['role:super_admin'])
        ->name('user-management.data');
    Route::post('/user-management/update', [UserMappingController::class, 'update'])
        ->middleware(['role:super_admin'])
        ->name('user-management.update');

    Route::get('/rbac', [RbacController::class, 'index'])
        ->middleware(['role:super_admin'])
        ->name('rbac');
    Route::post('/rbac/update', [RbacController::class, 'update'])
        ->middleware(['role:super_admin'])
        ->name('rbac.update');

    Route::get('/data-master', [DataMasterController::class, 'index'])
        ->middleware(['role:super_admin'])
        ->name('data-master');

    // CRUD reference table memakai kebijakan hapus hybrid: item terpakai hanya
    // boleh dinonaktifkan, item belum terpakai boleh dihapus permanen.
    Route::prefix('data-master')->name('data-master.')->middleware('role:super_admin')->group(function (): void {
        Route::get('/channel-notifikasi', [NotificationChannelController::class, 'index'])
            ->name('channel-notifikasi.index');
        Route::post('/channel-notifikasi', [NotificationChannelController::class, 'store'])
            ->name('channel-notifikasi.store');
        Route::post('/channel-notifikasi/{notificationChannel}/update', [NotificationChannelController::class, 'update'])
            ->whereUuid('notificationChannel')->name('channel-notifikasi.update');
        Route::post('/channel-notifikasi/{notificationChannel}/status', [NotificationChannelController::class, 'setEnabled'])
            ->whereUuid('notificationChannel')->name('channel-notifikasi.status');
        Route::post('/channel-notifikasi/{notificationChannel}/destroy', [NotificationChannelController::class, 'destroy'])
            ->whereUuid('notificationChannel')->name('channel-notifikasi.destroy');
        Route::post('/channel-notifikasi/{notificationChannel}/kebijakan-event', [NotificationChannelController::class, 'setEventPolicy'])
            ->whereUuid('notificationChannel')->name('channel-notifikasi.policy');
        Route::post('/channel-notifikasi/{notificationChannel}/konfigurasi-whatsapp', [NotificationChannelController::class, 'updateWhatsAppConfig'])
            ->whereUuid('notificationChannel')->name('channel-notifikasi.whatsapp-config');

        Route::post('/eselon', [DataMasterEselonController::class, 'store'])->name('eselon.store');
        Route::post('/eselon/{eselon}/update', [DataMasterEselonController::class, 'update'])
            ->whereUuid('eselon')->name('eselon.update');
        Route::post('/eselon/{eselon}/toggle-aktif', [DataMasterEselonController::class, 'toggle'])
            ->whereUuid('eselon')->name('eselon.toggle');
        Route::post('/eselon/{eselon}/destroy', [DataMasterEselonController::class, 'destroy'])
            ->whereUuid('eselon')->name('eselon.destroy');

        Route::post('/jenjang-pendidikan', [DataMasterJenjangPendidikanController::class, 'store'])->name('jenjang-pendidikan.store');
        Route::post('/jenjang-pendidikan/{jenjang}/update', [DataMasterJenjangPendidikanController::class, 'update'])
            ->whereUuid('jenjang')->name('jenjang-pendidikan.update');
        Route::post('/jenjang-pendidikan/{jenjang}/toggle-aktif', [DataMasterJenjangPendidikanController::class, 'toggle'])
            ->whereUuid('jenjang')->name('jenjang-pendidikan.toggle');
        Route::post('/jenjang-pendidikan/{jenjang}/destroy', [DataMasterJenjangPendidikanController::class, 'destroy'])
            ->whereUuid('jenjang')->name('jenjang-pendidikan.destroy');

        Route::post('/program-studi', [DataMasterProgramStudiController::class, 'store'])
            ->middleware('permission:reference_tables.manage')->name('program-studi.store');
        Route::post('/program-studi/{programStudi}/update', [DataMasterProgramStudiController::class, 'update'])
            ->whereUuid('programStudi')->middleware('permission:reference_tables.manage')->name('program-studi.update');
        Route::post('/program-studi/{programStudi}/toggle-aktif', [DataMasterProgramStudiController::class, 'toggle'])
            ->whereUuid('programStudi')->middleware('permission:reference_tables.manage')->name('program-studi.toggle');
        Route::post('/program-studi/{programStudi}/destroy', [DataMasterProgramStudiController::class, 'destroy'])
            ->whereUuid('programStudi')->middleware('permission:reference_tables.manage')->name('program-studi.destroy');

        Route::post('/golongan', [DataMasterGolonganController::class, 'store'])->name('golongan.store');
        Route::post('/golongan/{golongan}/update', [DataMasterGolonganController::class, 'update'])
            ->whereUuid('golongan')->name('golongan.update');
        Route::post('/golongan/{golongan}/toggle-aktif', [DataMasterGolonganController::class, 'toggle'])
            ->whereUuid('golongan')->name('golongan.toggle');
        Route::post('/golongan/{golongan}/destroy', [DataMasterGolonganController::class, 'destroy'])
            ->whereUuid('golongan')->name('golongan.destroy');

        Route::post('/jenis-jabatan', [DataMasterJenisJabatanController::class, 'store'])->name('jenis-jabatan.store');
        Route::post('/jenis-jabatan/{jenisJabatan}/update', [DataMasterJenisJabatanController::class, 'update'])
            ->whereUuid('jenisJabatan')->name('jenis-jabatan.update');
        Route::post('/jenis-jabatan/{jenisJabatan}/toggle-aktif', [DataMasterJenisJabatanController::class, 'toggle'])
            ->whereUuid('jenisJabatan')->name('jenis-jabatan.toggle');
        Route::post('/jenis-jabatan/{jenisJabatan}/destroy', [DataMasterJenisJabatanController::class, 'destroy'])
            ->whereUuid('jenisJabatan')->name('jenis-jabatan.destroy');

        Route::post('/jabatan', [DataMasterJabatanController::class, 'store'])->name('jabatan.store');
        Route::post('/jabatan/{jabatan}/update', [DataMasterJabatanController::class, 'update'])
            ->whereUuid('jabatan')->name('jabatan.update');
        Route::post('/jabatan/{jabatan}/toggle-aktif', [DataMasterJabatanController::class, 'toggle'])
            ->whereUuid('jabatan')->name('jabatan.toggle');
        Route::post('/jabatan/{jabatan}/destroy', [DataMasterJabatanController::class, 'destroy'])
            ->whereUuid('jabatan')->name('jabatan.destroy');

        Route::post('/unit-kerja', [DataMasterUnitKerjaController::class, 'store'])->name('unit-kerja.store');
        Route::post('/unit-kerja/{unitKerja}/update', [DataMasterUnitKerjaController::class, 'update'])
            ->whereUuid('unitKerja')->name('unit-kerja.update');
        Route::post('/unit-kerja/{unitKerja}/toggle-aktif', [DataMasterUnitKerjaController::class, 'toggle'])
            ->whereUuid('unitKerja')->name('unit-kerja.toggle');
        Route::post('/unit-kerja/{unitKerja}/destroy', [DataMasterUnitKerjaController::class, 'destroy'])
            ->whereUuid('unitKerja')->name('unit-kerja.destroy');

        Route::post('/status-pegawai', [DataMasterStatusPegawaiController::class, 'store'])->name('status-pegawai.store');
        Route::post('/status-pegawai/{statusPegawai}/update', [DataMasterStatusPegawaiController::class, 'update'])
            ->whereUuid('statusPegawai')->name('status-pegawai.update');
        Route::post('/status-pegawai/{statusPegawai}/toggle-aktif', [DataMasterStatusPegawaiController::class, 'toggle'])
            ->whereUuid('statusPegawai')->name('status-pegawai.toggle');
        Route::post('/status-pegawai/{statusPegawai}/destroy', [DataMasterStatusPegawaiController::class, 'destroy'])
            ->whereUuid('statusPegawai')->name('status-pegawai.destroy');
    });

    Route::get('/cuti/rekap', [CutiController::class, 'rekap'])
        ->middleware(['permission:cuti.read_all'])
        ->name('cuti.rekap');
    Route::get('/cuti/administrasi-saldo', [LeaveBalanceController::class, 'administrasi'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan', 'permission:cuti.balance.reconcile,cuti.manual.manage'])
        ->name('cuti.saldo.administrasi');
    Route::middleware(['role:super_admin,admin_kepegawaian', 'permission:cuti.balance.reconcile'])
        ->prefix('cuti/rekonsiliasi-tahunan')
        ->name('cuti.reconciliation.')
        ->group(function (): void {
            Route::post('/{employee}', [LeaveUsageController::class, 'reconcile'])
                ->whereUuid('employee')
                ->name('store');
            Route::post('/{reconciliation}/koreksi', [LeaveUsageController::class, 'correct'])
                ->whereUuid('reconciliation')
                ->name('correct');
            Route::get('/{reconciliation}/dokumen/{document}/unduh', [LeaveUsageController::class, 'downloadDocument'])
                ->whereUuid('reconciliation')
                ->whereUuid('document')
                ->name('document.download');
        });
    Route::middleware(['role:super_admin,admin_kepegawaian,pimpinan', 'permission:cuti.manual.manage'])
        ->prefix('cuti/pemakaian-manual')
        ->name('cuti.manual.')
        ->group(function (): void {
            Route::get('/penyetuju/cari', [ManualLeaveUsageController::class, 'lookupApprovers'])
                ->middleware('throttle:60,1')
                ->name('approver-lookup');
            Route::post('/{employee}', [ManualLeaveUsageController::class, 'store'])
                ->whereUuid('employee')
                ->name('store');
            Route::post('/{usage}/koreksi', [ManualLeaveUsageController::class, 'correct'])
                ->whereUuid('usage')
                ->name('correct');
            Route::post('/{usage}/batalkan', [ManualLeaveUsageController::class, 'cancel'])
                ->whereUuid('usage')
                ->name('cancel');
            Route::get('/{usage}/dokumen/{document}', [ManualLeaveUsageController::class, 'download'])
                ->whereUuid(['usage', 'document'])
                ->name('download');
        });
    Route::get('/cuti/pegawai/cari', CutiEmployeeLookupController::class)
        ->middleware(['permission:cuti.read_all', 'throttle:60,1'])
        ->name('cuti.employee-lookup');
    Route::get('/cuti/laporan', [CutiReportController::class, 'preview'])
        ->middleware(['permission:cuti.read_all'])
        ->name('cuti.laporan');
    Route::get('/cuti/laporan/pdf', [CutiReportController::class, 'pdf'])
        ->middleware(['permission:cuti.read_all'])
        ->name('cuti.laporan.pdf');
    Route::get('/cuti/laporan/excel', [CutiReportController::class, 'excel'])
        ->middleware(['permission:cuti.read_all'])
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
        ->middleware(['permission:ews.configure'])
        ->name('ews.config');
    Route::post('/konfigurasi/update', [EwsConfigController::class, 'update'])
        ->middleware(['permission:ews.configure'])
        ->name('ews.config.update');

    Route::get('/laporan/export-pegawai', [LaporanController::class, 'exportPegawai'])
        ->middleware(['permission:employees.read'])
        ->name('laporan.pegawai');

    Route::get('/laporan/export-pegawai/preview', [LaporanController::class, 'exportPegawaiPreview'])
        ->middleware(['permission:employees.read'])
        ->name('laporan.pegawai.preview');

    Route::get('/laporan/export-pegawai/excel', [LaporanController::class, 'exportPegawaiExcel'])
        ->middleware(['permission:employees.read'])
        ->name('laporan.pegawai.excel');

    Route::get('/laporan/export-pegawai/pdf', [LaporanController::class, 'exportPegawaiPdf'])
        ->middleware(['permission:employees.read'])
        ->name('laporan.pegawai.pdf');

    Route::get('/laporan/export-pegawai/pdf-nominatif', [LaporanController::class, 'exportPegawaiNominatifPdf'])
        ->middleware(['permission:employees.read'])
        ->name('laporan.pegawai.pdf-nominatif');

    Route::post('/laporan/export-pegawai/custom', [LaporanController::class, 'exportPegawaiCustom'])
        ->middleware(['permission:employees.read'])
        ->name('laporan.pegawai.custom');

    Route::get('/laporan/kepangkatan', [PimpinanReportController::class, 'rankHistories'])
        ->middleware(['permission:employee_histories.export'])
        ->name('laporan.kepangkatan');

    Route::get('/laporan/kepangkatan/excel', [PimpinanReportController::class, 'exportRankHistoriesExcel'])
        ->middleware(['permission:employee_histories.export'])
        ->name('laporan.kepangkatan.excel');

    Route::get('/laporan/kepangkatan/pdf', [PimpinanReportController::class, 'exportRankHistoriesPdf'])
        ->middleware(['permission:employee_histories.export'])
        ->name('laporan.kepangkatan.pdf');
    Route::get('/pegawai', Index::class)
        ->middleware(['permission:employees.read'])
        ->name('data-pegawai');
    Route::post('/pegawai/sk-requirements', [SkRequirementController::class, 'update'])
        ->middleware(['permission:sk_requirements.manage'])
        ->name('sk-requirements.update');
    Route::get('/pegawai/create', Create::class)
        ->middleware(['permission:employees.create'])
        ->name('pegawai.create');
    Route::post('/pegawai', [PegawaiController::class, 'store'])
        ->middleware(['permission:employees.create'])
        ->name('pegawai.store');
    Route::post('/pegawai/status', [PegawaiController::class, 'changeStatus'])
        ->middleware(['permission:employees.update'])
        ->name('pegawai.status.update');
    Route::get('/pegawai/{id}', Show::class)
        ->whereUuid('id')
        ->middleware(['permission:employees.read'])
        ->name('pegawai.show');
    Route::get('/pegawai/{id}/edit', Edit::class)
        ->whereUuid('id')
        ->middleware(['permission:employees.update'])
        ->name('pegawai.edit');
    Route::get('/pegawai/{employee}/attachment-riwayat/{type}/{history}/unduh', EmployeeHistoryAttachmentController::class)
        ->whereUuid('employee')
        ->whereUuid('history')
        ->whereIn('type', ['rank', 'position', 'salary', 'appointment', 'discipline', 'education', 'status', 'status-snapshot'])
        ->middleware(['permission:employees.read,employee_histories.read,dokumen_sk.read'])
        ->name('pegawai.history-attachments.download');
    Route::get('/pegawai/{id}/cari-kepala-bagian', EmployeeSupervisorLookupController::class)
        ->whereUuid('id')
        ->middleware(['permission:employees.update', 'throttle:60,1'])
        ->name('pegawai.supervisor-lookup');
    Route::post('/pegawai/{id}', [PegawaiController::class, 'update'])
        ->whereUuid('id')
        ->middleware(['permission:employees.update'])
        ->name('pegawai.update');
    Route::post('/pegawai/{id}/kinerja-baik', [PegawaiController::class, 'updatePerformanceFlag'])
        ->whereUuid('id')
        ->middleware(['permission:employees.update'])
        ->name('pegawai.kinerja.update');
    Route::post('/pegawai/{id}/satyalancana-eligibility', [PegawaiController::class, 'updateSatyalancanaEligibility'])
        ->whereUuid('id')
        ->middleware(['permission:employees.update'])
        ->name('pegawai.satyalancana.update');
    Route::post('/pegawai/{id}/delete', [PegawaiController::class, 'destroy'])
        ->whereUuid('id')
        ->middleware(['permission:employees.deactivate'])
        ->name('pegawai.destroy');
    Route::post('/pegawai/{id}/restore', [PegawaiController::class, 'restore'])
        ->whereUuid('id')
        ->middleware(['permission:employees.restore'])
        ->name('pegawai.restore');
    Route::post('/pegawai/{id}/riwayat', [PegawaiController::class, 'storeRiwayat'])
        ->whereUuid('id')
        ->middleware(['permission:employees.update'])
        ->name('pegawai.riwayat.store');
    Route::post('/pegawai/{id}/assign-atasan', [PegawaiController::class, 'assignAtasan'])
        ->whereUuid('id')
        ->middleware(['permission:employees.update'])
        ->name('pegawai.assign-atasan');

    Route::get('/dashboard/cuti/saldo', [LeaveBalanceController::class, 'showMyBalanceWeb'])
        ->middleware('permission:cuti.balance.read')
        ->name('cuti.saldo');

    Route::get('/pegawai/legacy', function () {
        return redirect()->route('data-pegawai');
    })->name('pegawai.index');

    // Parameter memakai model binding ber-UUID agar id rusak berhenti sebagai 404
    // di layer route, bukan menjadi error database.
    Route::get('/hari-libur', [HariLiburController::class, 'index'])
        ->middleware(['permission:hari_libur.read'])
        ->name('hari-libur');
    Route::post('/hari-libur', [HariLiburController::class, 'store'])
        ->middleware(['permission:hari_libur.create'])
        ->name('hari-libur.store');
    Route::get('/hari-libur/{hariLibur}/edit', [HariLiburController::class, 'edit'])
        ->middleware(['permission:hari_libur.update'])
        ->whereUuid('hariLibur')
        ->name('hari-libur.edit');
    Route::put('/hari-libur/{hariLibur}', [HariLiburController::class, 'update'])
        ->middleware(['permission:hari_libur.update'])
        ->whereUuid('hariLibur')
        ->name('hari-libur.update');
    Route::delete('/hari-libur/{hariLibur}', [HariLiburController::class, 'destroy'])
        ->middleware(['permission:hari_libur.delete'])
        ->whereUuid('hariLibur')
        ->name('hari-libur.destroy');

    Route::get('/hari-libur/legacy', function () {
        return redirect()->route('hari-libur');
    })->name('hari-libur.index');

    Route::get('/dashboard/cuti', [CutiController::class, 'index'])
        ->middleware('permission:cuti.read_own,cuti.read_all')
        ->name('cuti');
    Route::get('/dashboard/cuti/create', [CutiController::class, 'create'])
        ->middleware(['role:super_admin,admin_kepegawaian,kepala_bagian,pegawai', 'permission:cuti.create'])
        ->name('cuti.create');
    Route::post('/dashboard/cuti', [CutiController::class, 'store'])
        ->middleware(['role:super_admin,admin_kepegawaian,kepala_bagian,pegawai', 'permission:cuti.create'])
        ->name('cuti.store');
    Route::patch('/dashboard/cuti/{leaveRequest}/resubmit', [CutiController::class, 'resubmit'])
        ->middleware(['role:super_admin,admin_kepegawaian,kepala_bagian,pegawai', 'permission:cuti.create'])
        ->name('cuti.resubmit')
        ->whereUuid('leaveRequest');
    Route::get('/dashboard/cuti/{leaveRequest}/formulir-pdf', [CutiController::class, 'formulirPdf'])
        ->name('cuti.formulir-pdf')
        ->whereUuid('leaveRequest');
    Route::get('/dashboard/cuti/{leaveRequest}/lampiran', [CutiController::class, 'downloadAttachment'])
        ->name('cuti.attachment.download')
        ->whereUuid('leaveRequest');
    // Antrean dan tindakan approval mempertahankan allowlist role sebagai pagar kasar.
    // Kelayakan approver per-tahap (person-based) tetap ditegakkan di service, tanpa permission RBAC stage.
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
    Route::post('/cuti/{id}/decline', [CutiController::class, 'decline'])
        ->middleware(['role:super_admin,pimpinan,kepala_bagian,admin_kepegawaian,pegawai'])
        ->name('cuti.decline')
        ->whereUuid('id');
    Route::post('/cuti/{leave}/penangguhan-tugas-dinas', [CutiController::class, 'recordDutyPostponement'])
        ->middleware(['role:super_admin,pimpinan,kepala_bagian,admin_kepegawaian,pegawai'])
        ->name('cuti.penangguhan-tugas-dinas')
        ->whereUuid('leave');
    Route::get('/dashboard/cuti/{id}', [CutiController::class, 'show'])
        ->name('cuti.show')
        ->whereUuid('id');

    // Seluruh operasi konfigurasi rantai memakai satu permission: cuti.configure.
    Route::get('/cuti/konfigurasi-approval', [CutiConfigController::class, 'index'])
        ->middleware(['permission:cuti.configure'])
        ->name('cuti.config');
    Route::post('/cuti/konfigurasi-approval', [CutiConfigController::class, 'update'])
        ->middleware(['permission:cuti.configure'])
        ->name('cuti.config.update');
    Route::post('/cuti/konfigurasi-approval/backfill', [CutiConfigController::class, 'backfill'])
        ->middleware(['permission:cuti.configure'])
        ->name('cuti.config.backfill');
    Route::post('/cuti/konfigurasi-approval/pybmc-global', [CutiConfigController::class, 'updateGlobalPybmc'])
        ->middleware(['permission:cuti.configure'])
        ->name('cuti.config.pybmc-global');
    Route::post('/cuti/konfigurasi-approval/unit', [CutiConfigController::class, 'applyTemplateToUnit'])
        ->middleware(['permission:cuti.configure'])
        ->name('cuti.config.unit-template.apply');
    Route::post('/cuti/konfigurasi-approval/pegawai/{employee}', [CutiConfigController::class, 'storeEmployeeChain'])
        ->middleware(['permission:cuti.configure'])
        ->name('cuti.config.employee-chain.store')
        ->whereUuid('employee');

    // Query string diteruskan agar tautan berfilter yang dibagikan atau dibookmark tidak kehilangan
    // filternya secara diam-diam. Setiap filter tetap divalidasi ulang di tujuan.
    Route::get('/dashboard/cuti/legacy', function () {
        return redirect()->route('cuti', request()->query());
    })->name('cuti.index');

    Route::get('/cuti', function () {
        return redirect()->route('cuti', request()->query());
    });

    Route::get('/dashboard/dokumen', [DokumenController::class, 'index'])
        ->middleware(['permission:dokumen_sk.read,employees.read'])
        ->name('dokumen');
    Route::post('/dashboard/dokumen/upload', [DokumenController::class, 'store'])
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('dokumen.store');
    Route::post('/dashboard/dokumen/{id}', [DokumenController::class, 'update'])
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('dokumen.update')
        ->whereUuid('id');
    Route::get('/dashboard/dokumen/{id}', [DokumenController::class, 'show'])
        ->middleware(['permission:dokumen_sk.read,employees.read'])
        ->name('dokumen.show')
        ->whereUuid('id');
    Route::get('/dashboard/dokumen/{id}/download', [DokumenController::class, 'download'])
        ->middleware(['permission:dokumen_sk.read,employees.read'])
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
        ->middleware(['permission:audit_logs.read'])
        ->name('audit-log');
    Route::get('/dashboard/audit/{id}', [AuditController::class, 'show'])
        ->middleware(['permission:audit_logs.read'])
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

    // =========================================================================
    // SWITCH & REVERT ROLE
    // =========================================================================

    // Gate route eksplisit: aksi switch permission-driven — role asli apa pun yang
    // role efektifnya memiliki users.switch_role dapat mensimulasikan role yang
    // LEBIH RENDAH dari role aslinya (hierarki ROLE_RANKS dipaksa di FormRequest dan
    // SwitchRoleAction; anti-chain "simulasi sudah aktif" tetap berlaku).
    Route::post('/switch-role', [SwitchRoleController::class, 'switchRole'])
        ->middleware(['permission:users.switch_role'])
        ->name('switch-role');

    // Jalur pemulihan hanya memerlukan autentikasi. Ia sengaja dikecualikan dari role efektif agar
    // pengguna tetap mendapatkan form revert ketika record role target sudah tidak terdaftar.
    Route::get('/revert-role', [SwitchRoleController::class, 'showRecovery'])
        ->withoutMiddleware('role:super_admin,admin_kepegawaian,pimpinan,kepala_bagian,pegawai')
        ->name('revert-role.recovery');

    Route::post('/revert-role', [SwitchRoleController::class, 'revertRole'])
        ->withoutMiddleware('role:super_admin,admin_kepegawaian,pimpinan,kepala_bagian,pegawai')
        ->name('revert-role');

    Route::get('/notifikasi', [NotificationController::class, 'index'])
        ->middleware('permission:notifications.read')
        ->name('notifications.index');

    Route::redirect('/notifications', '/notifikasi')
        ->middleware('permission:notifications.read');

    Route::get('/pegawai/export', [PegawaiController::class, 'export'])
        ->middleware(['permission:employees.read'])
        ->name('pegawai.export');

    // =========================================================================
    // ROUTES PIMPINAN
    // =========================================================================
    Route::middleware(['role:pimpinan'])
        ->prefix('pimpinan')
        ->name('pimpinan.')
        ->group(function () {
            Route::get('/', fn () => redirect()->route('pimpinan.dashboard'));
            Route::get('/dashboard', [PimpinanDashboardController::class, 'index'])->name('dashboard');

            // Daftar dan detail pegawai Pimpinan tetap memakai permission granular selain gate role.
            Route::get('/pegawai', [PimpinanEmployeeController::class, 'index'])
                ->middleware('permission:employees.read')
                ->name('pegawai.index');
            Route::get('/pegawai/{employee}', [PimpinanEmployeeController::class, 'show'])
                ->middleware('permission:employees.read')
                ->whereUuid('employee')
                ->name('pegawai.show');
            Route::get('/pegawai/{employee}/dokumen/{document}/unduh', [PimpinanEmployeeController::class, 'downloadDocument'])
                ->middleware('permission:employees.read')
                ->whereUuid('employee')
                ->whereUuid('document')
                ->name('pegawai.documents.download');
            Route::get('/pegawai/{employee}/hukuman-disiplin/{history}/unduh', [PimpinanEmployeeController::class, 'downloadDisciplineAttachment'])
                ->middleware('permission:employees.read')
                ->whereUuid('employee')
                ->whereUuid('history')
                ->name('pegawai.discipline-attachments.download');
            Route::get('/pegawai/{employee}/status/{history}/unduh', [PimpinanEmployeeController::class, 'downloadStatusAttachment'])
                ->middleware('permission:employees.read')
                ->whereUuid('employee')
                ->whereUuid('history')
                ->name('pegawai.status-attachments.download');
            Route::get('/pegawai/{employee}/attachment-riwayat/{type}/{history}/unduh', [PimpinanEmployeeController::class, 'downloadHistoryAttachment'])
                ->middleware('permission:employees.read')
                ->whereUuid('employee')
                ->whereUuid('history')
                ->whereIn('type', ['rank', 'position', 'salary', 'appointment', 'education'])
                ->name('pegawai.history-attachments.download');

            Route::get('/cuti', [PimpinanLeaveController::class, 'index'])->name('cuti.index');
            Route::get('/cuti/{leave}', [PimpinanLeaveController::class, 'show'])
                ->whereUuid('leave')
                ->name('cuti.show');
            Route::post('/cuti/{leave}/decision', [PimpinanLeaveDecisionController::class, 'store'])
                ->whereUuid('leave')
                ->name('cuti.decision');
            Route::post('/cuti/{leave}/penangguhan-tugas-dinas', [PimpinanLeaveDecisionController::class, 'recordDutyPostponement'])
                ->whereUuid('leave')
                ->name('cuti.penangguhan-tugas-dinas');
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

            Route::get('/laporan/kepangkatan', fn () => redirect()->route('laporan.kepangkatan'))->name('laporan.kepangkatan');
            Route::get('/laporan/nominatif', fn () => redirect()->route('laporan.pegawai'))->name('laporan.nominatif');
            Route::get('/laporan/pegawai', fn () => redirect()->route('laporan.pegawai'))->name('laporan.pegawai');
            Route::get('/laporan/cuti', fn () => redirect()->route('cuti.rekap'))->name('laporan.cuti');
            Route::get('/laporan', fn () => redirect()->route('laporan.pegawai'))->name('laporan.index');
        });

    Route::middleware(['role:kepala_bagian'])
        ->prefix('kepala-bagian')
        ->name('kepala-bagian.')
        ->group(function (): void {
            Route::get('/', fn () => redirect()->route('kepala-bagian.dashboard'));
            Route::get('/dashboard', [KepalaBagianDashboardController::class, 'index'])->name('dashboard');
            Route::get('/search', KepalaBagianSearchController::class)
                ->middleware('throttle:60,1')
                ->name('search');

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
            Route::post('/cuti/{leave}/penangguhan-tugas-dinas', [KepalaBagianLeaveDecisionController::class, 'recordDutyPostponement'])
                ->whereUuid('leave')
                ->name('cuti.penangguhan-tugas-dinas');
            Route::get('/cuti/{leave}/lampiran', [KepalaBagianLeaveController::class, 'downloadAttachment'])
                ->whereUuid('leave')
                ->name('cuti.attachment.download');

            Route::get('/ews', [KepalaBagianEwsController::class, 'index'])->name('ews.index');
        });
});
