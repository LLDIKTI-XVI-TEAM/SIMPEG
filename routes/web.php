<?php

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CutiConfigController;
use App\Http\Controllers\Admin\CutiController;
use App\Http\Controllers\Admin\DokumenController;
use App\Http\Controllers\Admin\EmployeeImportController;
use App\Http\Controllers\Admin\EwsConfigController;
use App\Http\Controllers\Admin\EwsController;
use App\Http\Controllers\Admin\GlobalSearchController;
use App\Http\Controllers\Admin\HariLiburController;
use App\Http\Controllers\Admin\LaporanController;
use App\Http\Controllers\Admin\LeaveBalanceController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\PegawaiController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\RbacController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserMappingController;
use App\Http\Controllers\Auth\KeycloakAuthController;
use App\Http\Controllers\Cuti\VerifyLeaveProofController;
use App\Http\Controllers\PimpinanDashboardController;
use App\Http\Controllers\PimpinanEmployeeController;
use App\Http\Controllers\PimpinanEwsController;
use App\Http\Controllers\PimpinanLeaveController;
use App\Http\Controllers\PimpinanLeaveDecisionController;
use App\Http\Controllers\PimpinanLeaveDocumentController;
use App\Http\Controllers\PimpinanReportController;
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
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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
    Route::get('/dashboard', function (Request $request, ListActiveEwsAlertsAction $ewsAlerts) {
        $user = $request->user();
        $role = $user?->role;

        if ($role === 'pimpinan') {
            return redirect()->route('pimpinan.dashboard');
        }

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
                : (in_array($role, ['super_admin', 'admin_kepegawaian'], true) ? route('ews') : '#ews-section'),
        ]);
    })->name('dashboard');

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
        ->middleware(['role:super_admin,admin_kepegawaian'])
        ->name('cuti.rekap');

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

    Route::get('/laporan/export-pegawai/excel', function (Request $request) {
        // Ambil semua pegawai dengan field lengkap
        $employees = Employee::with(['jenisPegawai:id,nama', 'statusPegawai:id,nama'])
            ->orderBy('nama_lengkap')
            ->get();

        $allData = $employees->map(function ($emp) {
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
        });

        // Apply filters
        $search = strtolower(trim($request->query('search', '')));
        $unit = $request->query('unit', '');
        $golongan = $request->query('golongan', '');
        $jenis = $request->query('jenis', '');
        $status = $request->query('status', '');
        $sortBy = $request->query('sort', 'nama');
        $sortDir = $request->query('sort_dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $prefixField = trim($request->query('prefix_field', ''));
        $prefixValue = strtolower(trim($request->query('prefix_value', '')));
        $rowStart = max(1, (int) $request->query('row_start', 1));
        $rowEnd = $request->query('row_end', null);

        $filtered = $allData->filter(function ($p) use ($search, $unit, $golongan, $jenis, $status, $prefixField, $prefixValue) {
            if ($search !== '') {
                $pNama = strtolower($p['nama']);
                $pNip = str_replace(' ', '', $p['nip']);
                $q = str_replace(' ', '', $search);
                if (strpos($pNama, $search) === false && strpos($pNip, $q) === false) {
                    return false;
                }
            }
            if ($unit !== '' && $p['unit'] !== $unit) {
                return false;
            }
            if ($golongan !== '' && $p['golongan'] !== $golongan) {
                return false;
            }
            if ($jenis !== '' && $p['jenis'] !== $jenis) {
                return false;
            }
            if ($status !== '' && $p['status'] !== $status) {
                return false;
            }
            // Filter awalan (prefix)
            if ($prefixValue !== '' && isset($p[$prefixField])) {
                if (! str_starts_with(strtolower((string) $p[$prefixField]), $prefixValue)) {
                    return false;
                }
            }

            return true;
        });

        // Step 1: Potong row range DULU dari hasil filter (urutan asli)
        $slice = $filtered->slice($rowStart - 1, $rowEnd !== null ? ((int) $rowEnd - $rowStart + 1) : null)->values();

        // Step 2: Sort dari subset yang sudah dipotong
        $golonganOrder = ['IV/e' => 1, 'IV/d' => 2, 'IV/c' => 3, 'IV/b' => 4, 'IV/a' => 5, 'III/d' => 6, 'III/c' => 7, 'III/b' => 8, 'III/a' => 9, 'II/d' => 10, 'II/c' => 11, 'II/b' => 12, 'II/a' => 13, 'I/d' => 14, 'I/c' => 15, 'I/b' => 16, 'I/a' => 17];
        $sortFn = function ($p) use ($sortBy, $golonganOrder) {
            if ($sortBy === 'golongan') {
                return $golonganOrder[$p['golongan']] ?? 99;
            }

            return strtolower((string) ($p[$sortBy] ?? ''));
        };
        $slice = ($sortDir === 'desc')
            ? $slice->sortByDesc($sortFn)->values()
            : $slice->sortBy($sortFn)->values();

        // Kolom yang dipilih (default semua)
        $allColumns = [
            'no' => 'No',
            'nip' => 'NIP',
            'nama' => 'Nama Pegawai',
            'golongan' => 'Golongan',
            'jabatan' => 'Jabatan',
            'unit' => 'Unit Kerja',
            'jenis' => 'Jenis Pegawai',
            'status' => 'Status',
            'email' => 'Email',
            'no_hp' => 'No. HP',
            'tanggal_lahir' => 'Tanggal Lahir',
            'tanggal_pensiun' => 'Tgl. Pensiun',
            'pendidikan' => 'Pendidikan Terakhir',
            'pangkat' => 'Pangkat',
        ];
        $selectedKeys = $request->query('columns', array_keys($allColumns));
        $columns = collect($selectedKeys)
            ->filter(fn ($k) => isset($allColumns[$k]))
            ->mapWithKeys(fn ($k) => [$k => $allColumns[$k]]);
        if ($columns->isEmpty()) {
            $columns = collect($allColumns);
        }

        // Build Spreadsheet
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Daftar Pegawai');
        $sheet->setShowGridlines(false);

        $colLetters = range('A', 'Z');
        $colIndex = 0;

        foreach ($columns as $key => $label) {
            $letter = $colLetters[$colIndex];
            $width = match ($key) {
                'no' => 5, 'nip' => 22, 'nama' => 32, 'golongan' => 12, 'jabatan' => 36,
                'unit' => 20, 'jenis' => 16, 'status' => 18, 'email' => 28,
                'no_hp' => 18, 'tanggal_lahir' => 20, 'tanggal_pensiun' => 20,
                'pendidikan' => 22, 'pangkat' => 20, default => 18,
            };
            $sheet->getColumnDimension($letter)->setWidth($width);
            $sheet->setCellValue($letter.'1', $label);
            $colIndex++;
        }
        $lastCol = $colLetters[$colIndex - 1];
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle('A1:'.$lastCol.'1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10, 'name' => 'Calibri'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F5A83']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '69BFE3']]],
        ]);

        foreach ($slice as $i => $row) {
            $r = $i + 2;
            $ci = 0;
            foreach ($columns as $key => $label) {
                $letter = $colLetters[$ci];
                $value = ($key === 'no') ? ($i + 1) : ($row[$key] ?? '-');
                if (in_array($key, ['nip', 'no_hp'])) {
                    $sheet->setCellValueExplicit($letter.$r, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($letter.$r, $value);
                }
                $ci++;
            }
            $bg = ($i % 2 === 0) ? 'FFFFFF' : 'D9F2FB';
            $sheet->getRowDimension($r)->setRowHeight(20);
            $sheet->getStyle('A'.$r.':'.$lastCol.$r)->applyFromArray([
                'font' => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '111827']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '69BFE3']]],
            ]);
        }

        $lastRow = $slice->count() + 1;
        $sheet->freezePane('A2');
        if ($slice->isNotEmpty()) {
            $sheet->setAutoFilter('A1:'.$lastCol.$lastRow);
        }
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.3)->setRight(0.25)->setBottom(0.3)->setLeft(0.25);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);

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

    Route::post('/laporan/export-pegawai/custom', [LaporanController::class, 'exportPegawaiCustom'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('laporan.pegawai.custom');

    Route::get('/laporan/export-cuti', function () {
        $riwayatCuti = CutiController::$riwayatCuti;
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

        return view('admin.laporan.export-cuti', [
            'riwayatCuti' => $riwayatCuti,
            'pegawai' => $pegawai,
            'title' => 'Laporan - Export Cuti',
        ]);
    })->middleware(['role:super_admin,admin_kepegawaian,pimpinan'])
        ->name('laporan.cuti');

    Route::get('/laporan/export-cuti/excel', function (Request $request) {
        $riwayatCuti = CutiController::$riwayatCuti;
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
        $bulan = $request->query('bulan', '');
        $tahun = $request->query('tahun', '');
        $unit = $request->query('unit', '');
        $pegawaiNip = $request->query('pegawai', '');
        $jenis = $request->query('jenis', '');

        $filteredCuti = array_filter($riwayatCuti, function ($c) use ($bulan, $tahun, $unit, $pegawaiNip, $jenis) {
            $matchesBulan = true;
            $matchesTahun = true;

            if (! empty($c['mulai'])) {
                $parts = explode('-', $c['mulai']); // YYYY-MM-DD
                $year = $parts[0];
                $month = (string) (int) $parts[1]; // Convert "06" -> "6"

                if ($bulan !== '') {
                    $matchesBulan = ($month === $bulan);
                }
                if ($tahun !== '') {
                    $matchesTahun = ($year === $tahun);
                }
            }

            $matchesUnit = ($unit === '') || ($c['unit'] === $unit);
            $matchesPegawai = ($pegawaiNip === '') || ($c['nip'] === $pegawaiNip);
            $matchesJenis = ($jenis === '') || ($c['jenis'] === $jenis);

            return $matchesBulan && $matchesTahun && $matchesUnit && $matchesPegawai && $matchesJenis;
        });

        // Buat spreadsheet
        $spreadsheet = new Spreadsheet;

        // Sheet 1: Detail Cuti Pegawai
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Detail Cuti Pegawai');

        // Column headers for Sheet 1
        $colsSheet1 = [
            'A' => ['No', 5],
            'B' => ['NIP', 24],
            'C' => ['Nama Pegawai', 32],
            'D' => ['Jenis Cuti', 20],
            'E' => ['Tanggal Mulai', 16],
            'F' => ['Tanggal Selesai', 16],
            'G' => ['Jumlah Hari', 14],
            'H' => ['Status', 15],
        ];

        foreach ($colsSheet1 as $col => [$label, $width]) {
            $sheet1->getColumnDimension($col)->setWidth($width);
            $sheet1->setCellValue($col.'1', $label);
        }
        $sheet1->getRowDimension(1)->setRowHeight(30);

        // Header style for Sheet 1 (Primary Blue #122E92, White Text, Bold, Centered, Borders)
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Calibri'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '122E92']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
        ];
        $sheet1->getStyle('A1:H1')->applyFromArray($headerStyle);

        // Populate Sheet 1 data
        $index1 = 0;
        foreach ($filteredCuti as $row) {
            $r = $index1 + 2;

            $sheet1->setCellValue('A'.$r, $index1 + 1);
            $sheet1->setCellValueExplicit('B'.$r, $row['nip'], DataType::TYPE_STRING);
            $sheet1->setCellValue('C'.$r, $row['nama']);
            $sheet1->setCellValue('D'.$r, $row['jenis']);
            $sheet1->setCellValue('E'.$r, $row['mulai']);
            $sheet1->setCellValue('F'.$r, $row['selesai']);
            $sheet1->setCellValue('G'.$r, $row['hari']);
            $sheet1->setCellValue('H'.$r, $row['status']);

            $sheet1->getRowDimension($r)->setRowHeight(18);

            // Alternating stripe: putih / biru muda #EEF2FF
            $bg = ($index1 % 2 === 0) ? 'FFFFFF' : 'EEF2FF';
            $sheet1->getStyle('A'.$r.':H'.$r)->applyFromArray([
                'font' => ['size' => 10, 'name' => 'Calibri'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
            ]);
            $index1++;
        }

        // Freeze header row & auto-filter for Sheet 1
        $sheet1->freezePane('A2');
        if ($index1 > 0) {
            $sheet1->setAutoFilter('A1:H'.($index1 + 1));
        }

        // Sheet 2: Ringkasan Cuti Pegawai
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Ringkasan Cuti Pegawai');

        // Column headers for Sheet 2
        $colsSheet2 = [
            'A' => ['No', 5],
            'B' => ['NIP', 24],
            'C' => ['Nama Pegawai', 32],
            'D' => ['Total Cuti Tahunan (Hari)', 24],
            'E' => ['Total Cuti Sakit (Hari)', 22],
            'F' => ['Total Cuti Melahirkan (Hari)', 26],
            'G' => ['Sisa Saldo Cuti Tahunan', 24],
        ];

        foreach ($colsSheet2 as $col => [$label, $width]) {
            $sheet2->getColumnDimension($col)->setWidth($width);
            $sheet2->setCellValue($col.'1', $label);
        }
        $sheet2->getRowDimension(1)->setRowHeight(30);
        $sheet2->getStyle('A1:G1')->applyFromArray($headerStyle);

        // Filter employees to match unit & pegawai selected
        $filteredPegawai = array_filter($pegawai, function ($p) use ($unit, $pegawaiNip) {
            $matchesUnit = ($unit === '') || ($p['unit'] === $unit);
            $matchesPegawai = ($pegawaiNip === '') || ($p['nip'] === $pegawaiNip);

            return $matchesUnit && $matchesPegawai;
        });

        // Determine target year
        $targetYear = ($tahun !== '') ? $tahun : '2026';

        // Populate Sheet 2 data
        $index2 = 0;
        foreach ($filteredPegawai as $p) {
            $r = $index2 + 2;

            // Calculate total approved leaves
            $empCuti = array_filter($riwayatCuti, function ($c) use ($p, $targetYear) {
                if ($c['nip'] !== $p['nip']) {
                    return false;
                }
                if ($c['status'] !== 'disetujui') {
                    return false;
                }
                if (! empty($c['mulai'])) {
                    $year = explode('-', $c['mulai'])[0];

                    return $year === $targetYear;
                }

                return false;
            });

            $totalTahunan = 0;
            $totalSakit = 0;
            $totalMelahirkan = 0;

            foreach ($empCuti as $c) {
                if ($c['jenis'] === 'Cuti Tahunan') {
                    $totalTahunan += $c['hari'];
                } elseif ($c['jenis'] === 'Cuti Sakit') {
                    $totalSakit += $c['hari'];
                } elseif ($c['jenis'] === 'Cuti Melahirkan') {
                    $totalMelahirkan += $c['hari'];
                }
            }

            $sisaSaldo = 12 - $totalTahunan;

            $sheet2->setCellValue('A'.$r, $index2 + 1);
            $sheet2->setCellValueExplicit('B'.$r, $p['nip'], DataType::TYPE_STRING);
            $sheet2->setCellValue('C'.$r, $p['nama']);
            $sheet2->setCellValue('D'.$r, $totalTahunan);
            $sheet2->setCellValue('E'.$r, $totalSakit);
            $sheet2->setCellValue('F'.$r, $totalMelahirkan);
            $sheet2->setCellValue('G'.$r, $sisaSaldo);

            $sheet2->getRowDimension($r)->setRowHeight(18);

            // Alternating stripe
            $bg = ($index2 % 2 === 0) ? 'FFFFFF' : 'EEF2FF';
            $sheet2->getStyle('A'.$r.':G'.$r)->applyFromArray([
                'font' => ['size' => 10, 'name' => 'Calibri'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
            ]);

            $index2++;
        }

        // Freeze header row & auto-filter for Sheet 2
        $sheet2->freezePane('A2');
        if ($index2 > 0) {
            $sheet2->setAutoFilter('A1:G'.($index2 + 1));
        }

        $spreadsheet->setActiveSheetIndex(0);

        // Determine filename
        $namaBulan = [
            '1' => 'Januari',
            '2' => 'Februari',
            '3' => 'Maret',
            '4' => 'April',
            '5' => 'Mei',
            '6' => 'Juni',
            '7' => 'Juli',
            '8' => 'Agustus',
            '9' => 'September',
            '10' => 'Oktober',
            '11' => 'November',
            '12' => 'Desember',
        ];
        $periodeStr = '';
        if ($bulan && $tahun) {
            $periodeStr = ($namaBulan[$bulan] ?? '').'_'.$tahun;
        } elseif ($bulan) {
            $periodeStr = $namaBulan[$bulan] ?? '';
        } elseif ($tahun) {
            $periodeStr = $tahun;
        } else {
            $periodeStr = 'Semua_Periode';
        }
        $filename = 'Rekap_Cuti_'.$periodeStr.'_'.now()->format('Ymd').'.xlsx';

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
        ->name('laporan.cuti.excel');

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
