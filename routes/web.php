<?php

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CutiConfigController;
use App\Http\Controllers\Admin\CutiController;
use App\Http\Controllers\Admin\CutiReportController;
use App\Http\Controllers\Admin\DokumenController;
use App\Http\Controllers\Admin\EmployeeImportController;
use App\Http\Controllers\Admin\EwsConfigController;
use App\Http\Controllers\Admin\EwsController;
use App\Http\Controllers\Admin\GlobalSearchController;
use App\Http\Controllers\Admin\HariLiburController;
use App\Http\Controllers\Admin\LeaveBalanceController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\PegawaiController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\RbacController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserMappingController;
use App\Http\Controllers\Auth\KeycloakAuthController;
use App\Http\Controllers\Cuti\VerifyLeaveProofController;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::get('/login', [KeycloakAuthController::class, 'redirectToKeycloak'])->name('login');
Route::get('/login/keycloak', [KeycloakAuthController::class, 'redirectToKeycloak'])->name('auth.keycloak.redirect');
Route::get('/auth/keycloak/callback', [KeycloakAuthController::class, 'handleCallback'])->name('auth.keycloak.callback');
Route::post('/logout', [KeycloakAuthController::class, 'logout'])->name('logout');

Route::get('/cuti/verifikasi/{token}', VerifyLeaveProofController::class)
    ->middleware('throttle:60,1')
    ->where('token', '[A-Za-z0-9_-]{64,120}')
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
    Route::get('/dashboard', function (Request $request, ListActiveEwsAlertsAction $ewsAlerts) {
        $user = $request->user();
        $role = $user?->role;
        $isPegawai = $role === 'pegawai';
        $employeeId = $isPegawai ? (string) ($user?->employee_id ?? '') : null;
        $dashboardEwsData = $employeeId !== '' || ! $isPegawai
            ? $ewsAlerts->execute(null, null, $employeeId)
            : ['alerts' => []];
        $dashboardEwsAlerts = $dashboardEwsData['alerts'];

        return view('dashboard', [
            'dashboardEwsAlerts' => array_slice($dashboardEwsAlerts, 0, 5),
            'dashboardEwsTotal' => count($dashboardEwsAlerts),
            'dashboardEwsUrgent' => collect($dashboardEwsAlerts)->where('urgency', 'danger')->count(),
            'dashboardEwsWarning' => collect($dashboardEwsAlerts)->where('urgency', 'warning')->count(),
            'dashboardEwsInfo' => collect($dashboardEwsAlerts)->where('urgency', 'success')->count(),
            'dashboardEwsLink' => $isPegawai
                ? route('ews.saya')
                : (in_array($role, ['super_admin', 'admin_kepegawaian', 'pimpinan'], true) ? route('ews') : '#ews-section'),
        ]);
    })->name('dashboard');

    Route::get('/admin/search', [GlobalSearchController::class, 'search'])->name('global.search');

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

    Route::get('/cuti/rekap', [CutiController::class, 'rekap'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('cuti.rekap');
    Route::get('/cuti/laporan', [CutiReportController::class, 'preview'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('cuti.laporan');
    Route::get('/cuti/laporan/pdf', [CutiReportController::class, 'pdf'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('cuti.laporan.pdf');
    Route::get('/cuti/laporan/excel', [CutiReportController::class, 'excel'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('cuti.laporan.excel');

    Route::get('/konfigurasi', [EwsConfigController::class, 'index'])
        ->middleware(['role:super_admin'])
        ->name('ews.config');
    Route::post('/konfigurasi/update', [EwsConfigController::class, 'update'])
        ->middleware(['role:super_admin'])
        ->name('ews.config.update');

    Route::get('/laporan/export-pegawai', function () {
        $pegawai = Employee::all()->map(function ($emp) {
            return [
                'id' => $emp->id,
                'nama' => $emp->nama_lengkap,
                'nip' => $emp->nip,
                'unit' => $emp->unitKerja?->nama ?? '-',
                'golongan' => $emp->golongan_terakhir ?? '-',
                'jenis' => $emp->jenisPegawai?->nama ?? '-',
                'status' => $emp->status_aktif ?? 'Aktif',
            ];
        })->toArray();

        return view('admin.laporan.export-pegawai', [
            'pegawai' => $pegawai,
            'title' => 'Laporan - Export Pegawai',
        ]);
    })->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('laporan.pegawai');

    Route::get('/laporan/export-pegawai/excel', function (Request $request) {
        $pegawai = Employee::all()->map(function ($emp) {
            return [
                'id' => $emp->id,
                'nama' => $emp->nama_lengkap,
                'nip' => $emp->nip,
                'unit' => $emp->unitKerja?->nama ?? '-',
                'golongan' => $emp->golongan_terakhir ?? '-',
                'jenis' => $emp->jenisPegawai?->nama ?? '-',
                'status' => $emp->status_aktif ?? 'Aktif',
            ];
        })->toArray();

        // Apply filters
        $search = strtolower(trim($request->query('search', '')));
        $unit = $request->query('unit', '');
        $golongan = $request->query('golongan', '');
        $jenis = $request->query('jenis', '');
        $status = $request->query('status', '');
        $sortBy = $request->query('sort', 'nama');

        $filtered = array_filter($pegawai, function ($p) use ($search, $unit, $golongan, $jenis, $status) {
            $matchesSearch = true;
            if ($search !== '') {
                $pNama = strtolower($p['nama']);
                $pNip = str_replace(' ', '', $p['nip']);
                $qClean = str_replace(' ', '', $search);
                if (strpos($pNama, $search) === false && strpos($pNip, $qClean) === false) {
                    $matchesSearch = false;
                }
            }

            $matchesUnit = ($unit === '') || ($p['unit'] === $unit);
            $matchesGolongan = ($golongan === '') || ($p['golongan'] === $golongan);
            $matchesJenis = ($jenis === '') || ($p['jenis'] === $jenis);
            $matchesStatus = ($status === '') || ($p['status'] === $status);

            return $matchesSearch && $matchesUnit && $matchesGolongan && $matchesJenis && $matchesStatus;
        });

        // Apply sorting
        $golonganOrder = [
            'IV/e' => 1,
            'IV/d' => 2,
            'IV/c' => 3,
            'IV/b' => 4,
            'IV/a' => 5,
            'III/d' => 6,
            'III/c' => 7,
            'III/b' => 8,
            'III/a' => 9,
            'II/d' => 10,
            'II/c' => 11,
            'II/b' => 12,
            'II/a' => 13,
            'I/d' => 14,
            'I/c' => 15,
            'I/b' => 16,
            'I/a' => 17,
        ];

        usort($filtered, function ($a, $b) use ($sortBy, $golonganOrder) {
            if ($sortBy === 'nama') {
                return strcmp($a['nama'], $b['nama']);
            } elseif ($sortBy === 'nip') {
                return strcmp($a['nip'], $b['nip']);
            } elseif ($sortBy === 'golongan') {
                $rankA = $golonganOrder[$a['golongan']] ?? 99;
                $rankB = $golonganOrder[$b['golongan']] ?? 99;

                return $rankA - $rankB;
            }

            return 0;
        });

        // Buat spreadsheet
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Daftar Nominatif Pegawai');

        // Kolom sesuai US-9.1 AC-3: No, NIP, Nama, Golongan, Jabatan, Unit Kerja, Jenis Pegawai, Status.
        $cols = [
            'A' => ['No', 5],
            'B' => ['NIP', 24],
            'C' => ['Nama Pegawai', 32],
            'D' => ['Golongan', 12],
            'E' => ['Jabatan', 38],
            'F' => ['Unit Kerja', 20],
            'G' => ['Jenis Pegawai', 18],
            'H' => ['Status', 15],
        ];

        // Isi header & set lebar kolom
        foreach ($cols as $col => [$label, $width]) {
            $sheet->getColumnDimension($col)->setWidth($width);
            $sheet->setCellValue($col.'1', $label);
        }
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Style header (biru #122E92, teks putih, bold, centered, border)
        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Calibri'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '122E92']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
        ]);

        // Isi baris data
        $index = 0;
        foreach ($filtered as $row) {
            $r = $index + 2;

            $sheet->setCellValue('A'.$r, $index + 1);
            $sheet->setCellValueExplicit('B'.$r, $row['nip'], DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$r, $row['nama']);
            $sheet->setCellValue('D'.$r, $row['golongan']);
            $sheet->setCellValue('E'.$r, $row['jabatan']);
            $sheet->setCellValue('F'.$r, $row['unit']);
            $sheet->setCellValue('G'.$r, $row['jenis']);
            $sheet->setCellValue('H'.$r, $row['status']);

            $sheet->getRowDimension($r)->setRowHeight(18);

            // Alternating stripe: putih / biru muda
            $bg = ($index % 2 === 0) ? 'FFFFFF' : 'EEF2FF';
            $sheet->getStyle('A'.$r.':H'.$r)->applyFromArray([
                'font' => ['size' => 10, 'name' => 'Calibri'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
            ]);
            $index++;
        }

        // Freeze header row & auto-filter
        $sheet->freezePane('A2');
        if ($index > 0) {
            $sheet->setAutoFilter('A1:H'.($index + 1));
        }

        // Download: US-9.1 AC-5: Daftar_Pegawai_LLDIKTI_XVI_{tanggal}.xlsx
        $filename = 'Daftar_Pegawai_LLDIKTI_XVI_'.now()->format('Ymd').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    })->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('laporan.pegawai.excel');

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
    Route::get('/dashboard/dokumen/{id}', [DokumenController::class, 'show'])
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('dokumen.show')
        ->whereUuid('id');
    Route::get('/dashboard/dokumen/{id}/download', [DokumenController::class, 'download'])
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('dokumen.download')
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

    // UI DUMMY ROUTES FOR KEPALA BAGIAN
    Route::get('/kepala-bagian/bawahan', function () {
        return view('kabag.bawahan.index');
    })->name('kepala-bagian.bawahan.index');

    Route::get('/kepala-bagian/bawahan/{id}', function ($id) {
        return view('kabag.bawahan.show', compact('id'));
    })->name('kepala-bagian.bawahan.show');

    Route::get('/kepala-bagian/cuti', function () {
        return view('kabag.cuti.index');
    })->name('kepala-bagian.cuti.index');

    Route::get('/kepala-bagian/cuti/{id}', function ($id) {
        return view('kabag.cuti.show', compact('id'));
    })->name('kepala-bagian.cuti.show');

    Route::get('/kepala-bagian/ews', function () {
        return view('kabag.ews.index');
    })->name('kepala-bagian.ews.index');

});
