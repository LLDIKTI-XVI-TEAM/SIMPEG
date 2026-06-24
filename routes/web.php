<?php

use App\Http\Controllers\Auth\KeycloakAuthController;
use App\Http\Controllers\Admin\PegawaiController;
use App\Http\Controllers\Admin\HariLiburController;
use App\Http\Controllers\Admin\CutiController;
use App\Http\Controllers\Admin\CutiConfigController;
use App\Http\Controllers\Admin\EwsController;
use App\Http\Controllers\Admin\EwsConfigController;
use App\Http\Controllers\Admin\DokumenController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\UserMappingController;
use App\Http\Controllers\Admin\RbacController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('auth.keycloak.redirect');
})->name('home');

Route::get('/login', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('auth.keycloak.redirect');
})->name('login');

Route::get('/auth/keycloak/redirect', [KeycloakAuthController::class, 'redirectToKeycloak'])->name('auth.keycloak.redirect');
Route::get('/auth/keycloak/callback', [KeycloakAuthController::class, 'handleCallback'])->name('auth.keycloak.callback');
Route::post('/logout', [KeycloakAuthController::class, 'logout'])->name('logout');

Route::get('/dev-login', function () {
    $user = \App\Models\User::first();
    if (!$user) {
        $user = \App\Models\User::create([
            'name' => 'Demo Klabat',
            'email' => 'demo@example.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);
    } else {
        $user->name = 'Demo Klabat';
        if (empty($user->role)) {
            $user->role = 'super_admin';
        }
        $user->save();
    }
    Auth::login($user);
    session(['active_role' => $user->role ?? 'super_admin']);
    return redirect()->route('dashboard')->with('login_success', 'Selamat Datang! Anda berhasil masuk ke dalam sistem (Mode Dev).');
})->name('dev-login');

Route::middleware('keycloak.auth')->group(function (): void {

    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/change-role/{role}', function (\Illuminate\Http\Request $request, string $role) {
        abort_unless($request->user()?->role === $role, 403, 'Role aktif harus sesuai dengan role akun.');

        session(['active_role' => $role]);

        return back();
    })->whereIn('role', ['super_admin', 'admin_kepegawaian', 'pimpinan', 'atasan_langsung', 'pegawai'])
        ->name('change-role');

    Route::get('/pegawai/import-data', function () {
        return view('admin.pegawai.import');
    })->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.import'])
        ->name('pegawai.import');

    Route::get('/pegawai/import/template/{type}', function ($type) {
        $headers = [];
        
        if ($type === 'utama') {
            $headers = ['No', 'Nama Pegawai', 'Email Pegawai', 'Golongan', 'Jabatan', 'Kelas Jabatan', 'NIP', 'Nomor Telepon', 'Pangkat', 'Pendidikan Terakhir', 'Pensiun', 'Person', 'Person Formula', 'Prodi Pendidikan Terakhir', 'Status Kepegawaian', 'Tanggal Lahir'];
        } elseif ($type === 'pelengkap') {
            $headers = ['NIP', 'NIK', 'No KK', 'Tempat Lahir', 'Jenis Kelamin', 'Agama', 'Status Kawin', 'Golongan Darah'];
        } elseif ($type === 'kepangkatan') {
            $headers = ['NIP', 'Golongan', 'TMT Pangkat', 'No SK', 'Tanggal SK'];
        } elseif ($type === 'jabatan') {
            $headers = ['NIP', 'Nama Jabatan', 'Jenis Jabatan', 'Unit Kerja', 'TMT Jabatan', 'No SK', 'Tanggal SK'];
        } elseif ($type === 'kgb') {
            $headers = ['NIP', 'TMT KGB', 'Gaji Pokok', 'No SK', 'Tanggal SK'];
        } else {
            abort(404);
        }

        $format = strtolower((string) request('format', 'xlsx'));
        $format = in_array($format, ['xlsx', 'csv'], true) ? $format : 'xlsx';
        $filename = 'template_' . $type . '.' . $format;

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headers) {
                $output = fopen('php://output', 'w');
                echo "\xEF\xBB\xBF";
                fputcsv($output, $headers);
                fclose($output);
            }, $filename, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
                'Pragma'              => 'no-cache',
                'Expires'             => '0',
            ]);
        }
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Template ' . ucfirst($type));
        
        // Write headers
        foreach ($headers as $index => $header) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($colLetter . '1', $header);
            
            // Set header style (Primary Blue background, white bold text, centered, borders)
            $sheet->getStyle($colLetter . '1')->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10, 'name' => 'Calibri'],
                'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '122E92']],
                'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
            ]);
            
            // Auto fit column width
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
        
        $sheet->getRowDimension(1)->setRowHeight(30);
        
        // Add styled blank rows (e.g. 15 blank rows) with borders for a structured layout
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        for ($r = 2; $r <= 16; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(20);
            
            // Set border
            $sheet->getStyle('A' . $r . ':' . $lastColLetter . $r)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
            ]);
        }
        
        // Stream download
        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ]);
    })->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.import'])
        ->name('pegawai.import-template');

    Route::get('/ews', [EwsController::class, 'index'])
        ->middleware(['role:super_admin,admin_kepegawaian,pimpinan', 'permission:ews.read'])
        ->name('ews');

    Route::get('/laporan-export', function () {
        return view('dummy', ['title' => 'Laporan / Export']);
    })->middleware(['role:super_admin,admin_kepegawaian,pimpinan', 'permission:reports.export'])
        ->name('laporan');

    Route::get('/user-management', [UserMappingController::class, 'index'])
        ->middleware(['role:super_admin', 'permission:user_management.read'])
        ->name('user-management');
    Route::post('/user-management/update', [UserMappingController::class, 'update'])
        ->middleware(['role:super_admin', 'permission:user_management.update'])
        ->name('user-management.update');

    Route::get('/rbac', [RbacController::class, 'index'])
        ->middleware(['role:super_admin', 'permission:rbac.read'])
        ->name('rbac');
    Route::post('/rbac/update', [RbacController::class, 'update'])
        ->middleware(['role:super_admin', 'permission:rbac.update'])
        ->name('rbac.update');

    Route::get('/data-master', function () {
        return view('admin.data-master.index');
    })->middleware(['role:super_admin', 'permission:reference_data.manage'])
        ->name('data-master');


    Route::get('/pegawai/nonaktif-list', function () {
        return view('admin.pegawai.nonaktif');
    })->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.restore'])
        ->name('data-nonaktif');

    Route::get('/cuti/rekap', function () {
        return view('admin.cuti.rekap');
    })->middleware(['role:super_admin,admin_kepegawaian', 'permission:leave_balances.read'])
        ->name('cuti.rekap');

    Route::get('/konfigurasi', [EwsConfigController::class, 'index'])
        ->middleware(['role:super_admin', 'permission:ews.configure'])
        ->name('ews.config');
    Route::post('/konfigurasi/update', [EwsConfigController::class, 'update'])
        ->middleware(['role:super_admin', 'permission:ews.configure'])
        ->name('ews.config.update');

     Route::get('/laporan/export-pegawai', function () {
         $pegawai = PegawaiController::$pegawaiList;
         return view('admin.laporan.export-pegawai', [
             'pegawai' => $pegawai,
             'title' => 'Laporan - Export Pegawai'
         ]);
     })->middleware(['role:super_admin,admin_kepegawaian,pimpinan', 'permission:reports.export'])
         ->name('laporan.pegawai');

     Route::get('/laporan/export-pegawai/excel', function (\Illuminate\Http\Request $request) {
         $pegawai = PegawaiController::$pegawaiList;

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
             'IV/e' => 1, 'IV/d' => 2, 'IV/c' => 3, 'IV/b' => 4, 'IV/a' => 5,
             'III/d' => 6, 'III/c' => 7, 'III/b' => 8, 'III/a' => 9,
             'II/d' => 10, 'II/c' => 11, 'II/b' => 12, 'II/a' => 13,
             'I/d' => 14, 'I/c' => 15, 'I/b' => 16, 'I/a' => 17
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
         $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
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
             $sheet->setCellValue($col . '1', $label);
         }
         $sheet->getRowDimension(1)->setRowHeight(30);

         // Style header (biru #122E92, teks putih, bold, centered, border)
         $sheet->getStyle('A1:H1')->applyFromArray([
             'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Calibri'],
             'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '122E92']],
             'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
             'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
         ]);

         // Isi baris data
         $index = 0;
         foreach ($filtered as $row) {
             $r = $index + 2;

             $sheet->setCellValue('A'.$r, $index + 1);
             $sheet->setCellValueExplicit('B'.$r, $row['nip'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
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
                 'font'      => ['size' => 10, 'name' => 'Calibri'],
                 'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                 'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
                 'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
             ]);
             $index++;
         }

         // Freeze header row & auto-filter
         $sheet->freezePane('A2');
         if ($index > 0) {
             $sheet->setAutoFilter('A1:H' . ($index + 1));
         }

         // Download: US-9.1 AC-5: Daftar_Pegawai_LLDIKTI_XVI_{tanggal}.xlsx
         $filename = 'Daftar_Pegawai_LLDIKTI_XVI_' . now()->format('Ymd') . '.xlsx';

         return response()->streamDownload(function () use ($spreadsheet) {
             $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
             $writer->save('php://output');
         }, $filename, [
             'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
             'Content-Disposition' => 'attachment; filename="' . $filename . '"',
             'Cache-Control'       => 'no-cache, no-store, must-revalidate',
             'Pragma'              => 'no-cache',
             'Expires'             => '0',
         ]);
     })->middleware(['role:super_admin,admin_kepegawaian,pimpinan', 'permission:reports.export'])
         ->name('laporan.pegawai.excel');

    Route::get('/laporan/export-cuti', function () {
        $riwayatCuti = CutiController::$riwayatCuti;
        $pegawai = PegawaiController::$pegawaiList;
        return view('admin.laporan.export-cuti', [
            'riwayatCuti' => $riwayatCuti,
            'pegawai' => $pegawai,
            'title' => 'Laporan - Export Cuti'
        ]);
    })->middleware(['role:super_admin,admin_kepegawaian,pimpinan', 'permission:reports.export'])
        ->name('laporan.cuti');

    Route::get('/laporan/export-cuti/excel', function (\Illuminate\Http\Request $request) {
        $riwayatCuti = CutiController::$riwayatCuti;
        $pegawai = PegawaiController::$pegawaiList;

        // Apply filters
        $bulan = $request->query('bulan', '');
        $tahun = $request->query('tahun', '');
        $unit = $request->query('unit', '');
        $pegawaiNip = $request->query('pegawai', '');
        $jenis = $request->query('jenis', '');

        $filteredCuti = array_filter($riwayatCuti, function ($c) use ($bulan, $tahun, $unit, $pegawaiNip, $jenis) {
            $matchesBulan = true;
            $matchesTahun = true;

            if (!empty($c['mulai'])) {
                $parts = explode('-', $c['mulai']); // YYYY-MM-DD
                $year = $parts[0];
                $month = (string)(int)$parts[1]; // Convert "06" -> "6"

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
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        
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
            $sheet1->setCellValue($col . '1', $label);
        }
        $sheet1->getRowDimension(1)->setRowHeight(30);

        // Header style for Sheet 1 (Primary Blue #122E92, White Text, Bold, Centered, Borders)
        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Calibri'],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '122E92']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
        ];
        $sheet1->getStyle('A1:H1')->applyFromArray($headerStyle);

        // Populate Sheet 1 data
        $index1 = 0;
        foreach ($filteredCuti as $row) {
            $r = $index1 + 2;

            $sheet1->setCellValue('A'.$r, $index1 + 1);
            $sheet1->setCellValueExplicit('B'.$r, $row['nip'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
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
                'font'      => ['size' => 10, 'name' => 'Calibri'],
                'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
            ]);
            $index1++;
        }

        // Freeze header row & auto-filter for Sheet 1
        $sheet1->freezePane('A2');
        if ($index1 > 0) {
            $sheet1->setAutoFilter('A1:H' . ($index1 + 1));
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
            $sheet2->setCellValue($col . '1', $label);
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
                if (!empty($c['mulai'])) {
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
            $sheet2->setCellValueExplicit('B'.$r, $p['nip'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet2->setCellValue('C'.$r, $p['nama']);
            $sheet2->setCellValue('D'.$r, $totalTahunan);
            $sheet2->setCellValue('E'.$r, $totalSakit);
            $sheet2->setCellValue('F'.$r, $totalMelahirkan);
            $sheet2->setCellValue('G'.$r, $sisaSaldo);

            $sheet2->getRowDimension($r)->setRowHeight(18);

            // Alternating stripe
            $bg = ($index2 % 2 === 0) ? 'FFFFFF' : 'EEF2FF';
            $sheet2->getStyle('A'.$r.':G'.$r)->applyFromArray([
                'font'      => ['size' => 10, 'name' => 'Calibri'],
                'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
            ]);

            $index2++;
        }

        // Freeze header row & auto-filter for Sheet 2
        $sheet2->freezePane('A2');
        if ($index2 > 0) {
            $sheet2->setAutoFilter('A1:G' . ($index2 + 1));
        }

        $spreadsheet->setActiveSheetIndex(0);

        // Determine filename
        $namaBulan = [
            '1' => 'Januari', '2' => 'Februari', '3' => 'Maret', '4' => 'April',
            '5' => 'Mei', '6' => 'Juni', '7' => 'Juli', '8' => 'Agustus',
            '9' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];
        $periodeStr = '';
        if ($bulan && $tahun) {
            $periodeStr = ($namaBulan[$bulan] ?? '') . '_' . $tahun;
        } elseif ($bulan) {
            $periodeStr = $namaBulan[$bulan] ?? '';
        } elseif ($tahun) {
            $periodeStr = $tahun;
        } else {
            $periodeStr = 'Semua_Periode';
        }
        $filename = 'Rekap_Cuti_' . $periodeStr . '_' . now()->format('Ymd') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ]);
    })->middleware(['role:super_admin,admin_kepegawaian,pimpinan', 'permission:reports.export'])
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
    Route::post('/pegawai/{id}/delete', [PegawaiController::class, 'destroy'])
        ->whereUuid('id')
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.deactivate'])
        ->name('pegawai.destroy');
    Route::post('/pegawai/{id}/riwayat', [PegawaiController::class, 'storeRiwayat'])
        ->whereUuid('id')
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.update'])
        ->name('pegawai.riwayat.store');

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
    Route::post('/dashboard/cuti', [CutiController::class, 'store'])->name('cuti.store');
    Route::get('/cuti/approval', [CutiController::class, 'approval'])->name('cuti.approval');
    Route::post('/cuti/{id}/approve', [CutiController::class, 'approve'])->name('cuti.approve');
    Route::post('/cuti/{id}/postpone', [CutiController::class, 'postpone'])->name('cuti.postpone');
    Route::get('/dashboard/cuti/{id}', [CutiController::class, 'show'])->name('cuti.show');

    Route::get('/dashboard/cuti/legacy', function () {
        return redirect()->route('cuti');
    })->name('cuti.index');

    Route::get('/cuti', function () {
        return redirect()->route('cuti');
    });

    Route::get('/dashboard/dokumen', [DokumenController::class, 'index'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:documents.read'])
        ->name('dokumen');
    Route::post('/dashboard/dokumen/upload', [DokumenController::class, 'store'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:documents.create'])
        ->name('dokumen.store');
    Route::get('/dashboard/dokumen/{id}', [DokumenController::class, 'show'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:documents.read'])
        ->name('dokumen.show');
    Route::get('/dashboard/dokumen/{id}/download', [DokumenController::class, 'download'])
        ->middleware(['role:super_admin,admin_kepegawaian', 'permission:documents.download'])
        ->name('dokumen.download');

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
        ->name('audit-log.show');

    Route::get('/dashboard/audit/legacy', function () {
        return redirect()->route('audit-log');
    })->name('audit.index');

    Route::get('/audit', function () {
        return redirect()->route('audit-log');
    });

    Route::get('/dashboard/profil', [ProfileController::class, 'index'])->name('profil');
    Route::post('/dashboard/profil/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::get('/dashboard/pengaturan', [SettingsController::class, 'index'])
        ->middleware(['role:super_admin', 'permission:settings.manage'])
        ->name('pengaturan');
    Route::post('/dashboard/pengaturan', [SettingsController::class, 'update'])
        ->middleware(['role:super_admin', 'permission:settings.manage'])
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

    // ── Export Data Pegawai (.xlsx — PhpSpreadsheet) ─────────────────────────
    Route::get('/pegawai/export', function (\Illuminate\Http\Request $request) {
        $requestedNips = collect($request->input('nips', []))
            ->filter(fn ($nip) => is_string($nip) && trim($nip) !== '')
            ->map(fn (string $nip) => trim($nip))
            ->unique()
            ->values();

        $pegawaiData = \App\Models\Employee::query()
            ->with('jenisPegawai:id,nama')
            ->when(
                $requestedNips->isNotEmpty(),
                fn ($query) => $query->whereIn('nip', $requestedNips->all())
            )
            ->orderBy('nama_lengkap')
            ->get();

        if ($requestedNips->isNotEmpty()) {
            $requestedOrder = $requestedNips->flip();
            $pegawaiData = $pegawaiData
                ->sortBy(fn (\App\Models\Employee $employee) => $requestedOrder[$employee->nip] ?? PHP_INT_MAX)
                ->values();
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Pegawai');
        $sheet->setShowGridlines(false);

        $cols = [
            'A' => ['No',                         5],
            'B' => ['Nama Pegawai',              31],
            'C' => ['Email Pegawai',             29],
            'D' => ['Golongan',                  12],
            'E' => ['Jabatan',                   34],
            'F' => ['Kelas Jabatan',             15],
            'G' => ['NIP',                       23],
            'H' => ['Nomor Telepon',             19],
            'I' => ['Pangkat',                   18],
            'J' => ['Pendidikan Terakhir',       18],
            'K' => ['Pensiun',                   20],
            'L' => ['Person',                    22],
            'M' => ['Person Formula',            22],
            'N' => ['Prodi Pendidikan Terakhir', 28],
            'O' => ['Status Kepegawaian',        20],
            'P' => ['Tanggal Lahir',             20],
        ];

        foreach ($cols as $col => [$label, $width]) {
            $sheet->getColumnDimension($col)->setWidth($width);
            $sheet->setCellValue($col . '1', $label);
        }
        $sheet->getRowDimension(1)->setRowHeight(32);

        $sheet->getStyle('A1:P1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 10,
                'name' => 'Calibri',
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F5A83'],
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '69BFE3'],
                ],
            ],
        ]);

        foreach ($pegawaiData as $i => $employee) {
            $r = $i + 2;

            $sheet->setCellValue('A'.$r, $i + 1);
            $sheet->setCellValue('B'.$r, $employee->nama_lengkap);
            $sheet->setCellValue('C'.$r, $employee->email ?? '');
            $sheet->setCellValue('D'.$r, $employee->golongan_terakhir ?? '');
            $sheet->setCellValue('E'.$r, $employee->jabatan_terakhir ?? '');
            $sheet->setCellValue('F'.$r, $employee->kelas_jabatan ?? '');
            $sheet->setCellValueExplicit('G'.$r, $employee->nip, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('H'.$r, $employee->no_hp ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('I'.$r, $employee->pangkat_terakhir ?? '');
            $sheet->setCellValue('J'.$r, $employee->pendidikan_terakhir ?? '');

            if ($employee->tanggal_pensiun !== null) {
                $sheet->setCellValue('K'.$r, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($employee->tanggal_pensiun));
            }

            // Field Person dari file sumber belum disimpan terpisah di database.
            // Nama lengkap dipakai sebagai fallback agar struktur export tetap konsisten.
            $sheet->setCellValue('L'.$r, $employee->nama_lengkap);
            $sheet->setCellValue('M'.$r, $employee->nama_lengkap);
            $sheet->setCellValue('N'.$r, $employee->prodi_pendidikan_terakhir ?? '');
            $sheet->setCellValue('O'.$r, $employee->jenisPegawai?->nama ?? '');

            if ($employee->tanggal_lahir !== null) {
                $sheet->setCellValue('P'.$r, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($employee->tanggal_lahir));
            }

            $sheet->getRowDimension($r)->setRowHeight(21);
            $sheet->getStyle('A'.$r.':P'.$r)->applyFromArray([
                'font' => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '111827']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'D9F2FB'],
                ],
                'alignment' => [
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                    'wrapText' => false,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '69BFE3'],
                    ],
                ],
            ]);
        }

        $lastRow = $pegawaiData->count() + 1;

        if ($pegawaiData->isNotEmpty()) {
            $sheet->getStyle('A2:A'.$lastRow)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D2:D'.$lastRow)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F2:K'.$lastRow)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('O2:P'.$lastRow)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('K2:K'.$lastRow)->getNumberFormat()->setFormatCode('mmmm d, yyyy');
            $sheet->getStyle('P2:P'.$lastRow)->getNumberFormat()->setFormatCode('mmmm d, yyyy');
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:P'.$lastRow);
        $sheet->getPageSetup()
            ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()
            ->setTop(0.3)
            ->setRight(0.25)
            ->setBottom(0.3)
            ->setLeft(0.25);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);

        $filename = 'Data_Pegawai_SIMPEG_' . now()->format('Ymd') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ]);

    })->middleware(['role:super_admin,admin_kepegawaian', 'permission:employees.export'])
        ->name('pegawai.export');

});
