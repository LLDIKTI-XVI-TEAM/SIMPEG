<?php

namespace Tests\Feature;

use App\Actions\Cuti\ExportCutiExcelAction;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Queries\Cuti\CutiRekapQuery;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CutiExcelExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_excel_detail_memiliki_satu_kolom_nama_yang_tetap_aman_dari_formula(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create(['nama_lengkap' => '=SUM(A1:A2)', 'nip' => '199001010001']);
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Kontrak Kolom']);
        $this->createLeaveRequest($pegawai, $jenis, '2026-06-15');

        $spreadsheet = $this->loadWorkbook($this->actingAs($user)
            ->get(route('cuti.laporan.excel', ['pegawai' => $pegawai->id]))
            ->streamedContent());

        try {
            $detail = $spreadsheet->getSheetByName('Detail Cuti');
            $this->assertNotNull($detail);
            $this->assertSame(
                ['No', 'NIP', 'Nama', 'Jenis Cuti', 'Tanggal Mulai', 'Tanggal Selesai', 'Hari Kerja', 'Sumber', 'Status'],
                $detail->rangeToArray('A1:I1')[0],
            );
            $this->assertSame('I', $detail->getHighestDataColumn());
            $this->assertSame("'=SUM(A1:A2)", $detail->getCell('C2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $detail->getCell('C2')->getDataType());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_excel_memuat_tiga_sheet_data_kanonis_tanggal_typed_dan_saldo_materialized(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create([
            'nip' => '=1+1',
            'nama_lengkap' => '  +SUM(A1:A2)',
            'jabatan_terakhir' => 'Bagian XLSX',
        ]);
        $pegawaiLain = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => '@Cuti Excel']);
        $included = $this->createLeaveRequest($pegawai, $jenis, '2026-06-15');
        $included->update(['tanggal_selesai' => '2026-06-18']);
        $included->refresh();
        $this->createLeaveRequest($pegawaiLain, $jenis, '2026-06-16');
        LeaveBalance::create([
            'employee_id' => $pegawai->id,
            'tahun' => 2026,
            'sisa_n2' => 2,
            'sisa_n1' => 3,
            'sisa_tahun_berjalan' => 7,
            'sisa' => 12,
            'hangus' => 4,
        ]);
        $filters = ['pegawai' => $pegawai->id, 'periode' => '2026'];
        $expectedIds = app(CutiRekapQuery::class)->detailRows($filters)->pluck('id')->all();

        $response = $this->actingAs($user)->get(route('cuti.laporan.excel', $filters));

        $response->assertOk()->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertMatchesRegularExpression(
            '/attachment; filename=Rekap_Cuti_2026_\d{8}\.xlsx/',
            (string) $response->headers->get('Content-Disposition'),
        );

        $spreadsheet = $this->loadWorkbook($response->streamedContent());
        try {
            $this->assertSame(['Detail Cuti', 'Ringkasan Cuti', 'Saldo Cuti'], $spreadsheet->getSheetNames());
            $detail = $spreadsheet->getSheetByName('Detail Cuti');
            $summary = $spreadsheet->getSheetByName('Ringkasan Cuti');
            $balance = $spreadsheet->getSheetByName('Saldo Cuti');
            $this->assertNotNull($detail);
            $this->assertNotNull($summary);
            $this->assertNotNull($balance);
            $this->assertSame('A2', $detail->getFreezePane());
            $this->assertSame('A2', $summary->getFreezePane());
            $this->assertSame('A2', $balance->getFreezePane());
            $this->assertSame(
                ['No', 'NIP', 'Nama', 'Jenis Cuti', 'Tanggal Mulai', 'Tanggal Selesai', 'Hari Kerja', 'Sumber', 'Status'],
                $detail->rangeToArray('A1:I1')[0],
            );
            $this->assertSame(
                ['No', 'NIP', 'Nama Pegawai', 'Jenis Cuti', 'Total Hari Disetujui', 'Sisa Saldo Cuti Tahunan 2026'],
                $summary->rangeToArray('A1:F1')[0],
            );
            $this->assertSame(
                ['No', 'NIP', 'Nama', 'Tahun', 'Sisa N-2', 'Sisa N-1', 'Sisa Tahun Berjalan', 'Sisa', 'Hangus'],
                $balance->rangeToArray('A1:I1')[0],
            );
            // Pengajuan pada skenario ini masih menunggu approval sehingga ringkasan wajib kosong.
            $this->assertSame(1, $summary->getHighestDataRow());
            $this->assertSame('I', $detail->getHighestDataColumn());
            $this->assertSame('I', $balance->getHighestDataColumn());
            $this->assertSame($expectedIds, [$included->id]);
            $this->assertSame(2, $detail->getHighestDataRow());
            $this->assertSame(2, $balance->getHighestDataRow());
            $this->assertSame("'  +SUM(A1:A2)", $detail->getCell('C2')->getValue());
            $this->assertSame("'=1+1", $detail->getCell('B2')->getValue());
            $this->assertSame("'@Cuti Excel", $detail->getCell('D2')->getValue());
            $this->assertSame('Melalui SIMPEG', $detail->getCell('H2')->getValue());
            $this->assertSame('Menunggu Approver', $detail->getCell('I2')->getValue());
            foreach (['B2', 'C2', 'D2', 'H2', 'I2'] as $cell) {
                $this->assertSame(DataType::TYPE_STRING, $detail->getCell($cell)->getDataType());
            }
            $this->assertSame(Date::PHPToExcel($included->tanggal_mulai), $detail->getCell('E2')->getValue());
            $this->assertSame(Date::PHPToExcel($included->tanggal_selesai), $detail->getCell('F2')->getValue());
            $this->assertSame('yyyy-mm-dd', $detail->getStyle('E2')->getNumberFormat()->getFormatCode());
            $this->assertSame('yyyy-mm-dd', $detail->getStyle('F2')->getNumberFormat()->getFormatCode());
            foreach (['B2', 'C2'] as $cell) {
                $this->assertSame(DataType::TYPE_STRING, $balance->getCell($cell)->getDataType());
            }
            $this->assertSame(2, $balance->getCell('E2')->getValue());
            $this->assertSame(3, $balance->getCell('F2')->getValue());
            $this->assertSame(7, $balance->getCell('G2')->getValue());
            $this->assertSame(12, $balance->getCell('H2')->getValue());
            $this->assertSame(4, $balance->getCell('I2')->getValue());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_ringkasan_menjumlahkan_hari_disetujui_dan_memisahkan_jenis_cuti(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create(['nip' => '198001012006041001', 'nama_lengkap' => 'Pegawai Rekap']);
        $tahunan = RefJenisCuti::create(['nama' => 'Cuti Tahunan']);
        $sakit = RefJenisCuti::create(['nama' => 'Cuti Sakit']);

        // Dua pengajuan disetujui pada jenis yang sama wajib teragregasi menjadi satu baris.
        $approvedTahunanA = $this->createLeaveRequest($pegawai, $tahunan, '2026-06-01', 'disetujui', 3);
        $approvedTahunanB = $this->createLeaveRequest($pegawai, $tahunan, '2026-06-10', 'disetujui', 2);
        $approvedSakit = $this->createLeaveRequest($pegawai, $sakit, '2026-06-15', 'disetujui', 4);
        $this->createApprovedFact($pegawai, $tahunan, $approvedTahunanA, 3);
        $this->createApprovedFact($pegawai, $tahunan, $approvedTahunanB, 2);
        $this->createApprovedFact($pegawai, $sakit, $approvedSakit, 4);
        // Status non-final tidak boleh masuk hitungan saldo terpakai.
        $this->createLeaveRequest($pegawai, $tahunan, '2026-06-20', 'tidak_disetujui', 7);
        $this->createLeaveRequest($pegawai, $tahunan, '2026-06-25', 'menunggu_approval', 9);
        $this->createLeaveRequest($pegawai, $tahunan, '2026-06-27', 'ditangguhkan', 6);
        $this->createLeaveRequest($pegawai, $tahunan, '2026-06-28', 'perlu_perubahan', 8);
        LeaveBalance::create(['employee_id' => $pegawai->id, 'tahun' => 2026, 'sisa' => 6]);

        $spreadsheet = $this->loadWorkbook($this->actingAs($user)
            ->get(route('cuti.laporan.excel', ['pegawai' => $pegawai->id, 'periode' => '2026']))
            ->streamedContent());

        try {
            $summary = $spreadsheet->getSheetByName('Ringkasan Cuti');
            $this->assertNotNull($summary);
            $this->assertSame(3, $summary->getHighestDataRow());
            // formatData dimatikan agar total hari dibandingkan sebagai integer, bukan teks terformat.
            $rows = $summary->rangeToArray('A2:F3', null, true, false);
            $byJenis = collect($rows)->keyBy(3);

            $this->assertSame(5, $byJenis['Cuti Tahunan'][4]);
            $this->assertSame(4, $byJenis['Cuti Sakit'][4]);
            $this->assertSame(6, $byJenis['Cuti Tahunan'][5]);
            $this->assertSame('198001012006041001', $byJenis['Cuti Tahunan'][1]);
            $this->assertSame('Pegawai Rekap', $byJenis['Cuti Tahunan'][2]);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_excel_memakai_detail_source_aware_dan_ringkasan_hanya_fakta_aktif(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create([
            'nip' => "\t=1990",
            'nama_lengkap' => '  +Pegawai Source',
            'jabatan_terakhir' => 'Unit Excel Source',
        ]);
        $jenis = RefJenisCuti::create(['nama' => '@Cuti Source']);
        $approved = $this->createLeaveRequest($pegawai, $jenis, '2026-06-10', 'disetujui', 2);
        $this->createUsage($pegawai, $jenis, [
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'leave_request_id' => $approved->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-10',
            'effective_date' => '2026-06-10',
            'workdays' => 2,
        ]);
        $this->createUsage($pegawai, $jenis, [
            'start_date' => '2026-06-11',
            'end_date' => '2026-06-11',
            'effective_date' => '2026-06-11',
            'workdays' => 3,
            'administrative_note' => 'RAHASIA_EXCEL_MANUAL',
        ]);
        $this->createUsage($pegawai, $jenis, [
            'start_date' => '2026-06-12',
            'end_date' => '2026-06-12',
            'effective_date' => '2026-06-12',
            'workdays' => 4,
            'record_status' => LeaveUsageRecord::STATUS_CANCELLED,
            'correction_reason' => 'Dibatalkan dan tidak boleh masuk laporan.',
        ]);

        $spreadsheet = $this->loadWorkbook($this->actingAs($user)
            ->get(route('cuti.laporan.excel', [
                'pegawai' => $pegawai->id,
                'periode' => '2026-06',
            ]))->streamedContent());

        try {
            $detail = $spreadsheet->getSheetByName('Detail Cuti');
            $summary = $spreadsheet->getSheetByName('Ringkasan Cuti');
            $this->assertNotNull($detail);
            $this->assertNotNull($summary);
            $this->assertSame(
                ['No', 'NIP', 'Nama', 'Jenis Cuti', 'Tanggal Mulai', 'Tanggal Selesai', 'Hari Kerja', 'Sumber', 'Status'],
                $detail->rangeToArray('A1:I1')[0],
            );
            $this->assertSame(3, $detail->getHighestDataRow());
            $rows = collect($detail->rangeToArray('A2:I3', null, true, false))->keyBy(7);
            $this->assertSame('Disetujui melalui SIMPEG', $rows['Melalui SIMPEG'][8]);
            $this->assertSame('Disetujui di luar SIMPEG', $rows['Di luar SIMPEG'][8]);
            $this->assertSame("'\t=1990", $rows['Di luar SIMPEG'][1]);
            $this->assertSame("'  +Pegawai Source", $rows['Di luar SIMPEG'][2]);
            $this->assertSame("'@Cuti Source", $rows['Di luar SIMPEG'][3]);
            $this->assertSame(2, $summary->getHighestDataRow());
            $this->assertSame(5, $summary->getCell('E2')->getValue());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_ringkasan_memisahkan_pegawai_dan_jenis_bernama_sama(): void
    {
        $user = User::factory()->superAdmin()->create();
        $anwar = Employee::factory()->create(['nip' => '198001010001', 'nama_lengkap' => 'Anwar']);
        $budi = Employee::factory()->create(['nip' => '199002020002', 'nama_lengkap' => 'Budi']);
        // Skema ref_jenis_cuti tidak menjamin nama unik, jadi dua baris bernama sama harus tetap terpisah.
        $tahunanA = RefJenisCuti::create(['nama' => 'Cuti Tahunan']);
        $tahunanB = RefJenisCuti::create(['nama' => 'Cuti Tahunan']);
        $anwarA = $this->createLeaveRequest($anwar, $tahunanA, '2026-06-03', 'disetujui', 2);
        $budiA = $this->createLeaveRequest($budi, $tahunanA, '2026-06-04', 'disetujui', 3);
        $anwarB = $this->createLeaveRequest($anwar, $tahunanB, '2026-06-05', 'disetujui', 4);
        $this->createApprovedFact($anwar, $tahunanA, $anwarA, 2);
        $this->createApprovedFact($budi, $tahunanA, $budiA, 3);
        $this->createApprovedFact($anwar, $tahunanB, $anwarB, 4);

        $spreadsheet = $this->loadWorkbook($this->actingAs($user)
            ->get(route('cuti.laporan.excel', ['periode' => '2026']))
            ->streamedContent());

        try {
            $summary = $spreadsheet->getSheetByName('Ringkasan Cuti');
            $this->assertNotNull($summary);
            $this->assertSame(4, $summary->getHighestDataRow());
            $rows = $summary->rangeToArray('A2:F4', null, true, false);
            // Urutan mengikuti nama pegawai sehingga dua baris Anwar berdekatan sebelum Budi.
            $this->assertSame(['Anwar', 'Anwar', 'Budi'], array_column($rows, 2));
            // Dua jenis bernama sama membuat urutan antar keduanya seri, jadi yang dikunci adalah nilainya.
            $anwar = array_slice(array_column($rows, 4), 0, 2);
            sort($anwar);
            $this->assertSame([2, 4], $anwar);
            $this->assertSame(3, $rows[2][4]);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_ringkasan_mengabaikan_pegawai_tanpa_cuti_disetujui_dan_mengamankan_formula(): void
    {
        $user = User::factory()->superAdmin()->create();
        $berbahaya = Employee::factory()->create(['nip' => "\t=1980", 'nama_lengkap' => '  -Nama Berbahaya']);
        $tanpaCuti = Employee::factory()->create(['nama_lengkap' => 'Tanpa Cuti']);
        $jenis = RefJenisCuti::create(['nama' => '@Jenis Berbahaya']);
        $approved = $this->createLeaveRequest($berbahaya, $jenis, '2026-06-05', 'disetujui', 2);
        $this->createApprovedFact($berbahaya, $jenis, $approved, 2);
        // Saldo tanpa pengajuan disetujui tidak boleh memunculkan baris ringkasan.
        LeaveBalance::create(['employee_id' => $tanpaCuti->id, 'tahun' => 2026, 'sisa' => 12]);

        $spreadsheet = $this->loadWorkbook($this->actingAs($user)
            ->get(route('cuti.laporan.excel', ['periode' => '2026']))
            ->streamedContent());

        try {
            $summary = $spreadsheet->getSheetByName('Ringkasan Cuti');
            $this->assertNotNull($summary);
            $this->assertSame(2, $summary->getHighestDataRow());
            $this->assertSame("'\t=1980", $summary->getCell('B2')->getValue());
            $this->assertSame("'  -Nama Berbahaya", $summary->getCell('C2')->getValue());
            $this->assertSame("'@Jenis Berbahaya", $summary->getCell('D2')->getValue());
            // Saldo absen ditandai '-' mengikuti perilaku laporan pimpinan.
            $this->assertSame('-', $summary->getCell('F2')->getValue());
            foreach (['B2', 'C2', 'D2'] as $cell) {
                $this->assertSame(DataType::TYPE_STRING, $summary->getCell($cell)->getDataType());
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_tanpa_filter_periode_saldo_memakai_tahun_berjalan_dan_diberi_label(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Tahunan']);
        $tahunIni = (int) now()->year;
        $approved = $this->createLeaveRequest($pegawai, $jenis, $tahunIni.'-06-10', 'disetujui', 2);
        $this->createApprovedFact($pegawai, $jenis, $approved, 2);
        LeaveBalance::create(['employee_id' => $pegawai->id, 'tahun' => $tahunIni, 'sisa' => 11]);
        LeaveBalance::create(['employee_id' => $pegawai->id, 'tahun' => $tahunIni - 1, 'sisa' => 4]);

        $spreadsheet = $this->loadWorkbook($this->actingAs($user)
            ->get(route('cuti.laporan.excel'))
            ->streamedContent());

        try {
            $summary = $spreadsheet->getSheetByName('Ringkasan Cuti');
            $this->assertNotNull($summary);
            $this->assertSame('Sisa Saldo Cuti Tahunan '.$tahunIni, $summary->getCell('F1')->getValue());
            $this->assertSame(11, $summary->getCell('F2')->getValue());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_sheet_saldo_memuat_pegawai_bersaldo_tanpa_pengajuan_pada_periode_filter(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawaiBertransaksi = Employee::factory()->create(['nama_lengkap' => 'Pegawai Bertransaksi']);
        $pegawaiTanpaPengajuan = Employee::factory()->create(['nama_lengkap' => 'Pegawai Tanpa Pengajuan']);
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Tahunan']);

        $this->createLeaveRequest($pegawaiBertransaksi, $jenis, '2026-06-10', 'disetujui', 2);
        LeaveBalance::create(['employee_id' => $pegawaiBertransaksi->id, 'tahun' => 2026, 'sisa' => 10]);
        // Saldo bersifat state materialized, bukan turunan transaksi. Pegawai yang belum
        // punya pengajuan pada periode filter tetap punya hak cuti yang wajib terlapor.
        LeaveBalance::create(['employee_id' => $pegawaiTanpaPengajuan->id, 'tahun' => 2026, 'sisa' => 12]);

        $spreadsheet = $this->loadWorkbook($this->actingAs($user)
            ->get(route('cuti.laporan.excel', ['periode' => '2026']))
            ->streamedContent());

        try {
            $balance = $spreadsheet->getSheetByName('Saldo Cuti');
            $this->assertNotNull($balance);
            $namaTerekspor = collect($balance->rangeToArray('A2:C'.$balance->getHighestDataRow()))->pluck(2);
            $this->assertContains('Pegawai Bertransaksi', $namaTerekspor);
            $this->assertContains('Pegawai Tanpa Pengajuan', $namaTerekspor);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_nama_file_excel_mengikuti_periode_filter(): void
    {
        $user = User::factory()->superAdmin()->create();

        $tanpaPeriode = $this->actingAs($user)->get(route('cuti.laporan.excel'));
        $this->assertMatchesRegularExpression(
            '/attachment; filename=Rekap_Cuti_Semua_Tahun_\d{8}\.xlsx/',
            (string) $tanpaPeriode->headers->get('Content-Disposition'),
        );

        $bulanan = $this->actingAs($user)->get(route('cuti.laporan.excel', ['periode' => '2026-06']));
        $this->assertMatchesRegularExpression(
            '/attachment; filename=Rekap_Cuti_2026-06_\d{8}\.xlsx/',
            (string) $bulanan->headers->get('Content-Disposition'),
        );

        $namaBulan = $this->actingAs($user)->get(route('cuti.laporan.excel', ['periode' => 'Juni 2026']));
        $this->assertMatchesRegularExpression(
            '/attachment; filename=Rekap_Cuti_2026-06_\d{8}\.xlsx/',
            (string) $namaBulan->headers->get('Content-Disposition'),
        );
    }

    public function test_excel_mengamankan_semua_awalan_formula_dan_kontrol(): void
    {
        $user = User::factory()->superAdmin()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Aman']);
        $names = ['=cmd', '+cmd', '-cmd', '@cmd', "\tcmd", "\rcmd", "\ncmd"];
        foreach ($names as $index => $name) {
            $employee = Employee::factory()->create(['nama_lengkap' => $name]);
            $this->createLeaveRequest($employee, $jenis, '2026-06-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT));
        }

        $spreadsheet = $this->loadWorkbook($this->actingAs($user)
            ->get(route('cuti.laporan.excel'))
            ->streamedContent());
        try {
            $sheet = $spreadsheet->getSheetByName('Detail Cuti');
            $this->assertNotNull($sheet);
            foreach (range(2, 8) as $row) {
                $this->assertStringStartsWith("'", (string) $sheet->getCell('C'.$row)->getValue());
                $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C'.$row)->getDataType());
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    #[DataProvider('unsafeTextProvider')]
    public function test_safe_text_menetralkan_setiap_awalan_formula_dan_kontrol(string $value): void
    {
        $action = app(ExportCutiExcelAction::class);
        $method = new \ReflectionMethod($action, 'safeText');

        $this->assertSame("'".$value, $method->invoke($action, $value));
    }

    /** @return array<string, array{string}> */
    public static function unsafeTextProvider(): array
    {
        return [
            'sama dengan' => ['=cmd'],
            'spasi plus' => ['  +cmd'],
            'spasi minus' => [" \t-cmd"],
            'spasi at' => ['   @cmd'],
            'tab mentah' => ["\tcmd"],
            'carriage return mentah' => ["\rcmd"],
            'line feed mentah' => ["\ncmd"],
        ];
    }

    public function test_excel_mengamankan_setiap_field_teks_detail_dan_saldo(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create([
            'nip' => "\t=1980",
            'nama_lengkap' => '  -Nama Berbahaya',
        ]);
        $jenis = RefJenisCuti::create(['nama' => "\r@Jenis Berbahaya"]);
        $leaveRequest = $this->createLeaveRequest($pegawai, $jenis, '2026-06-20');
        LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 1,
            'step_type' => 'verifikator',
            'role_label' => "\n+Verifikator",
            'status' => 'active',
            'is_final' => false,
        ]);
        LeaveBalance::create(['employee_id' => $pegawai->id, 'tahun' => 2026]);

        $spreadsheet = $this->loadWorkbook($this->actingAs($user)
            ->get(route('cuti.laporan.excel', ['pegawai' => $pegawai->id]))
            ->streamedContent());
        try {
            $detail = $spreadsheet->getSheetByName('Detail Cuti');
            $balance = $spreadsheet->getSheetByName('Saldo Cuti');
            $this->assertNotNull($detail);
            $this->assertNotNull($balance);
            $this->assertSame("'\t=1980", $detail->getCell('B2')->getValue());
            $this->assertSame("'  -Nama Berbahaya", $detail->getCell('C2')->getValue());
            $this->assertSame("'\n@Jenis Berbahaya", $detail->getCell('D2')->getValue());
            $this->assertSame('Melalui SIMPEG', $detail->getCell('H2')->getValue());
            $this->assertSame("Menunggu \n+Verifikator", $detail->getCell('I2')->getValue());
            $this->assertSame("'\t=1980", $balance->getCell('B2')->getValue());
            $this->assertSame("'  -Nama Berbahaya", $balance->getCell('C2')->getValue());
            foreach (['B2', 'C2', 'D2', 'H2', 'I2'] as $cell) {
                $this->assertSame(DataType::TYPE_STRING, $detail->getCell($cell)->getDataType());
            }
            foreach (['B2', 'C2'] as $cell) {
                $this->assertSame(DataType::TYPE_STRING, $balance->getCell($cell)->getDataType());
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_excel_menolak_5001_baris_tanpa_truncation(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Excel Batas']);
        $now = now();
        foreach (array_chunk(range(1, 5000), 500) as $indexes) {
            $rows = [];
            foreach ($indexes as $index) {
                $rows[] = [
                    'id' => fake()->uuid(), 'employee_id' => $pegawai->id, 'jenis_cuti_id' => $jenis->id,
                    'tanggal_mulai' => '2026-06-10', 'tanggal_selesai' => '2026-06-10',
                    'jumlah_hari_kerja' => 1, 'alasan' => 'Batas Excel '.$index, 'status' => 'disetujui',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            LeaveRequest::query()->insert($rows);
        }
        $this->createUsage($pegawai, $jenis, [
            'start_date' => '2026-06-11',
            'end_date' => '2026-06-11',
            'effective_date' => '2026-06-11',
        ]);

        $response = $this->actingAs($user)
            ->from(route('cuti.laporan'))
            ->get(route('cuti.laporan.excel', ['pegawai' => $pegawai->id]));

        $response->assertRedirect(route('cuti.laporan'));
        $response->assertSessionHas('error', fn (string $message): bool => str_contains($message, '5001')
            && str_contains($message, '5000')
            && str_contains(strtolower($message), 'persempit filter'));
    }

    public function test_excel_menolak_5001_saldo_tanpa_truncation(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $now = now();

        foreach (array_chunk(range(1, 5001), 500) as $years) {
            $rows = [];
            foreach ($years as $year) {
                $rows[] = [
                    'id' => fake()->uuid(),
                    'employee_id' => $pegawai->id,
                    'tahun' => $year,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            LeaveBalance::query()->insert($rows);
        }

        $response = $this->actingAs($user)
            ->from(route('cuti.laporan'))
            ->get(route('cuti.laporan.excel', ['pegawai' => $pegawai->id]));

        $response->assertRedirect(route('cuti.laporan'));
        $response->assertSessionHas('error', fn (string $message): bool => str_contains($message, '5001')
            && str_contains($message, '5000')
            && str_contains(strtolower($message), 'saldo')
            && str_contains(strtolower($message), 'persempit filter'));
    }

    private function createLeaveRequest(
        Employee $employee,
        RefJenisCuti $jenis,
        string $date,
        string $status = 'menunggu_approval',
        int $hari = 1,
    ): LeaveRequest {
        return LeaveRequest::create([
            'employee_id' => $employee->id, 'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => $date, 'tanggal_selesai' => $date,
            'jumlah_hari_kerja' => $hari, 'alasan' => 'Data Excel', 'status' => $status,
        ]);
    }

    private function loadWorkbook(string $content): Spreadsheet
    {
        $file = tempnam(sys_get_temp_dir(), 'cuti-xlsx-');
        $this->assertNotFalse($file);
        file_put_contents($file, $content);

        try {
            return IOFactory::load($file);
        } finally {
            @unlink($file);
        }
    }

    private function createApprovedFact(
        Employee $employee,
        RefJenisCuti $jenis,
        LeaveRequest $request,
        int $workdays,
    ): LeaveUsageRecord {
        return $this->createUsage($employee, $jenis, [
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'leave_request_id' => $request->id,
            'usage_year' => (int) $request->tanggal_mulai->year,
            'effective_date' => $request->tanggal_mulai->toDateString(),
            'start_date' => $request->tanggal_mulai->toDateString(),
            'end_date' => $request->tanggal_selesai->toDateString(),
            'workdays' => $workdays,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createUsage(Employee $employee, RefJenisCuti $jenis, array $overrides = []): LeaveUsageRecord
    {
        $record = LeaveUsageRecord::query()->forceCreate(array_merge([
            'id' => fake()->uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => $jenis->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'reconciliation_set_id' => null,
            'leave_request_id' => null,
            'leave_request_case_id' => null,
            'usage_year' => 2026,
            'effective_date' => '2026-06-15',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'workdays' => 1,
            'administrative_note' => 'Fixture Excel source-aware.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'replaces_id' => null,
            'correction_reason' => null,
            'recorded_by' => null,
            'created_at' => '2026-06-15 08:00:00',
            'updated_at' => '2026-06-15 08:00:00',
        ], $overrides));

        return $record->source_type === LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL
            ? $this->attachValidManualApprovalSnapshot($record)
            : $record;
    }
}
