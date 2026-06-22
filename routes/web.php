<?php

use App\Http\Controllers\Auth\KeycloakAuthController;
use App\Http\Controllers\Admin\PegawaiController;
use App\Http\Controllers\Admin\HariLiburController;
use App\Http\Controllers\Admin\CutiController;
use App\Http\Controllers\Admin\DokumenController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::get('/login', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : view('auth.login');
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
        ]);
    } else {
        $user->name = 'Demo Klabat';
        $user->save();
    }
    Auth::login($user);
    return redirect()->route('dashboard');
})->name('dev-login');

Route::middleware('keycloak.auth')->group(function (): void {

    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/pegawai', [PegawaiController::class, 'index'])->name('data-pegawai');
    Route::get('/pegawai/create', [PegawaiController::class, 'create'])->name('pegawai.create');
    Route::post('/pegawai', [PegawaiController::class, 'store'])->name('pegawai.store');
    Route::get('/pegawai/{id}', [PegawaiController::class, 'show'])->name('pegawai.show');
    Route::get('/pegawai/{id}/edit', [PegawaiController::class, 'edit'])->name('pegawai.edit');
    Route::post('/pegawai/{id}', [PegawaiController::class, 'update'])->name('pegawai.update');
    Route::post('/pegawai/{id}/delete', [PegawaiController::class, 'destroy'])->name('pegawai.destroy');

    Route::get('/pegawai/legacy', function () {
        return redirect()->route('data-pegawai');
    })->name('pegawai.index');

    Route::get('/hari-libur', [HariLiburController::class, 'index'])->name('hari-libur');
    Route::post('/hari-libur', [HariLiburController::class, 'store'])->name('hari-libur.store');
    Route::get('/hari-libur/{id}/edit', [HariLiburController::class, 'edit'])->name('hari-libur.edit');
    Route::post('/hari-libur/{id}', [HariLiburController::class, 'update'])->name('hari-libur.update');
    Route::post('/hari-libur/{id}/delete', [HariLiburController::class, 'destroy'])->name('hari-libur.destroy');

    Route::get('/hari-libur/legacy', function () {
        return redirect()->route('hari-libur');
    })->name('hari-libur.index');

    Route::get('/dashboard/cuti', [CutiController::class, 'index'])->name('cuti');
    Route::post('/dashboard/cuti', [CutiController::class, 'store'])->name('cuti.store');
    Route::get('/dashboard/cuti/approval', [CutiController::class, 'approval'])->name('cuti.approval');
    Route::post('/dashboard/cuti/approval/{id}/approve', [CutiController::class, 'approve'])->name('cuti.approve');
    Route::post('/dashboard/cuti/approval/{id}/reject', [CutiController::class, 'reject'])->name('cuti.reject');
    Route::get('/dashboard/cuti/{id}', [CutiController::class, 'show'])->name('cuti.show');

    Route::get('/dashboard/cuti/legacy', function () {
        return redirect()->route('cuti');
    })->name('cuti.index');

    Route::get('/cuti', function () {
        return redirect()->route('cuti');
    });

    Route::get('/dashboard/dokumen', [DokumenController::class, 'index'])->name('dokumen');
    Route::post('/dashboard/dokumen/upload', [DokumenController::class, 'store'])->name('dokumen.store');
    Route::get('/dashboard/dokumen/{id}', [DokumenController::class, 'show'])->name('dokumen.show');
    Route::get('/dashboard/dokumen/{id}/download', [DokumenController::class, 'download'])->name('dokumen.download');

    Route::get('/dashboard/dokumen/legacy', function () {
        return redirect()->route('dokumen');
    })->name('dokumen.index');

    Route::get('/dokumen', function () {
        return redirect()->route('dokumen');
    });

    Route::get('/dashboard/audit', [AuditController::class, 'index'])->name('audit-log');
    Route::get('/dashboard/audit/{id}', [AuditController::class, 'show'])->name('audit-log.show');

    Route::get('/dashboard/audit/legacy', function () {
        return redirect()->route('audit-log');
    })->name('audit.index');

    Route::get('/audit', function () {
        return redirect()->route('audit-log');
    });

    Route::get('/dashboard/profil', [ProfileController::class, 'index'])->name('profil');
    Route::post('/dashboard/profil/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::get('/dashboard/pengaturan', [SettingsController::class, 'index'])->name('pengaturan');
    Route::post('/dashboard/pengaturan', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('/dashboard/pengaturan/legacy', function () {
        return redirect()->route('pengaturan');
    })->name('settings.index');

    Route::get('/pengaturan', function () {
        return redirect()->route('pengaturan');
    });

    Route::get('/dashboard/Pengaturan', function () {
        return redirect()->route('pengaturan');
    });

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');

    // ── Export Data Pegawai (.xlsx — PhpSpreadsheet) ─────────────────────────
    Route::get('/pegawai/export', function () {
        $pegawaiData = [
            ['nama'=>'Ahmad Fauzi',                   'email'=>'ahmadfauzi@gmail.com',      'golongan'=>'III/c', 'jabatan'=>'Analis Kepegawaian',                  'kelas_jabatan'=>'8', 'nip'=>'19850312201001 1 001', 'telepon'=>'081234567890', 'pangkat'=>'Penata Tkt. I',  'pendidikan'=>'S1', 'tgl_lahir'=>'March 12, 1985',     'pensiun'=>'Abd Rahim Har',           'atasan'=>'Abd Rahim Har',           'person_familia'=>'Abd Rahim Har',           'prodi'=>'Manajemen',                  'jenis'=>'PNS'],
            ['nama'=>'Siti Rahayu',                   'email'=>'sitirahayu@gmail.com',       'golongan'=>'II/d',  'jabatan'=>'Analis Ahli Madya',                       'kelas_jabatan'=>'8', 'nip'=>'19901120201501 2 003', 'telepon'=>'085298765432', 'pangkat'=>'Pemula Tkt. I', 'pendidikan'=>'S2', 'tgl_lahir'=>'November 20, 1990',  'pensiun'=>'Riza Hamzah',             'atasan'=>'Riza Hamzah',             'person_familia'=>'Riza Hamzah',             'prodi'=>'Administrasi Pemerintahan',  'jenis'=>'PNS'],
            ['nama'=>'Sabrina Rossa Adriani Wibowo',  'email'=>'sabrinarossa24@gmail.com',   'golongan'=>'III/a', 'jabatan'=>'Analis SDM Aparatur Ahli Pertama',     'kelas_jabatan'=>'8', 'nip'=>'20261210820500 0 04',  'telepon'=>'081285066001', 'pangkat'=>'Pemula Tkt. I', 'pendidikan'=>'S1', 'tgl_lahir'=>'October 12, 1998',   'pensiun'=>'Sabrina Rossa',           'atasan'=>'Sabrina Rossa',           'person_familia'=>'Sabrina Rossa',           'prodi'=>'Informatika',                'jenis'=>'CPNS'],
            ['nama'=>'Cimma Sari Oktariani Di Silapu','email'=>'sikaemma@gmail.com',         'golongan'=>'III/c', 'jabatan'=>'Pranata SDM Terampil',                 'kelas_jabatan'=>'6', 'nip'=>'26110820520600 0 04',  'telepon'=>'081258206006', 'pangkat'=>'Pengatur DO',   'pendidikan'=>'S1', 'tgl_lahir'=>'October 28, 2001',   'pensiun'=>'Cimma Sari Oktariani Di', 'atasan'=>'Cimma Sari Oktariani Di', 'person_familia'=>'Cimma Sari Oktariani Di', 'prodi'=>'Manajemen Informatika',     'jenis'=>'CPNS'],
            ['nama'=>'Nurarningsih Dumbea, S.P.',     'email'=>'rainingdumbea47@gmail.com',  'golongan'=>'III/b', 'jabatan'=>'Pejabat Lelang Operational',             'kelas_jabatan'=>'7', 'nip'=>'19880123202 1 005',    'telepon'=>'082302200526', 'pangkat'=>'Penata Tkt. I', 'pendidikan'=>'S1', 'tgl_lahir'=>'January 23, 1988',   'pensiun'=>'Naning Dumbea',           'atasan'=>'Naning Dumbea',           'person_familia'=>'Faria Dana Puri',         'prodi'=>'Agribisnis',                 'jenis'=>'PPPK'],
            ['nama'=>'Nadia Kusuma',                  'email'=>'nadiakusuma@gmail.com',      'golongan'=>'II/c',  'jabatan'=>'Pengelola Kepegawaian',                  'kelas_jabatan'=>'6', 'nip'=>'19950822202001 2 002', 'telepon'=>'081299887766', 'pangkat'=>'Pengatur',      'pendidikan'=>'S1', 'tgl_lahir'=>'August 22, 1995',    'pensiun'=>'Nadia Kusuma',            'atasan'=>'Nadia Kusuma',            'person_familia'=>'Nadia Kusuma',            'prodi'=>'Ilmu Pemerintahan',          'jenis'=>'PPNPN'],
            ['nama'=>'Yucna Dara, S.P., M.M.',       'email'=>'hanaryog101@gmail.com',      'golongan'=>'III/b', 'jabatan'=>'Analis Ahli Pertama',                    'kelas_jabatan'=>'8', 'nip'=>'19840120099 2 002',    'telepon'=>'081284920002', 'pangkat'=>'Penata Tkt. I', 'pendidikan'=>'S2', 'tgl_lahir'=>'January 20, 1984',   'pensiun'=>'Yucna Dara',              'atasan'=>'Ingat Gobel',             'person_familia'=>'Ingat Gobel',             'prodi'=>'Teknik Informatika',         'jenis'=>'PNS'],
            ['nama'=>'Siraajuddin Laluv, OE., M.',   'email'=>'siraajuddinlaluv@gmail.com', 'golongan'=>'IV/a',  'jabatan'=>'Pengolah Data dan Informasi',            'kelas_jabatan'=>'7', 'nip'=>'19721231984 0 1062',   'telepon'=>'081238500',    'pangkat'=>'Pembina',       'pendidikan'=>'S1', 'tgl_lahir'=>'December 31, 1972',  'pensiun'=>'Siraajuddin Laluv',       'atasan'=>'Siraajuddin Laluv',       'person_familia'=>'Siraajuddin Laluv',       'prodi'=>'Manajemen',                  'jenis'=>'PNS'],
        ];

        // Buat spreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Pegawai');

        // Definisi kolom: [huruf => [label, lebar]]
        $cols = [
            'A' => ['No',                        5],
            'B' => ['Nama Pegawai',              32],
            'C' => ['Email Pegawai',             30],
            'D' => ['Golongan',                  12],
            'E' => ['Jabatan',                   38],
            'F' => ['Kelas Jabatan',             14],
            'G' => ['NIP',                       24],
            'H' => ['Nomor Telepon',             18],
            'I' => ['Pangkat',                   20],
            'J' => ['Pendidikan Terakhir',       20],
            'K' => ['Pensiun',                   22],
            'L' => ['Person',                    24],
            'M' => ['Person Familia',            24],
            'N' => ['Prodi Pendidikan Terakhir', 28],
            'O' => ['Status Kepegawaian',        20],
            'P' => ['Tanggal Lahir',             20],
        ];

        // Isi header & set lebar kolom
        foreach ($cols as $col => [$label, $width]) {
            $sheet->getColumnDimension($col)->setWidth($width);
            $sheet->setCellValue($col . '1', $label);
        }
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Style header (biru #122E92, teks putih, bold, centered, border)
        $sheet->getStyle('A1:P1')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Calibri'],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '122E92']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
        ]);

        // Isi baris data
        foreach ($pegawaiData as $i => $row) {
            $r = $i + 2;

            $sheet->setCellValue('A'.$r, $i + 1);
            $sheet->setCellValue('B'.$r, $row['nama']);
            $sheet->setCellValue('C'.$r, $row['email']);
            $sheet->setCellValue('D'.$r, $row['golongan']);
            $sheet->setCellValue('E'.$r, $row['jabatan']);
            $sheet->setCellValue('F'.$r, $row['kelas_jabatan']);
            $sheet->setCellValueExplicit('G'.$r, $row['nip'],     \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('H'.$r, $row['telepon'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('I'.$r, $row['pangkat']);
            $sheet->setCellValue('J'.$r, $row['pendidikan']);
            $sheet->setCellValue('K'.$r, $row['pensiun']);
            $sheet->setCellValue('L'.$r, $row['atasan']);
            $sheet->setCellValue('M'.$r, $row['person_familia']);
            $sheet->setCellValue('N'.$r, $row['prodi']);
            $sheet->setCellValue('O'.$r, $row['jenis']);
            $sheet->setCellValue('P'.$r, $row['tgl_lahir']);

            $sheet->getRowDimension($r)->setRowHeight(18);

            // Alternating stripe: putih / biru muda
            $bg = ($i % 2 === 0) ? 'FFFFFF' : 'EEF2FF';
            $sheet->getStyle('A'.$r.':P'.$r)->applyFromArray([
                'font'      => ['size' => 10, 'name' => 'Calibri'],
                'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
            ]);
        }

        // Freeze header row & auto-filter
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:P' . (count($pegawaiData) + 1));

        // Download
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

    })->name('pegawai.export');

});
