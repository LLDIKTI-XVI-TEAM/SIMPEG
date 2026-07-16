<?php

namespace Tests\Feature;

use App\Actions\Cuti\ExportCutiExcelAction;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
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

    public function test_excel_memuat_dua_sheet_data_kanonis_tanggal_typed_dan_saldo_materialized(): void
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
        $expectedIds = (new CutiRekapQuery)->detailRows($filters)->pluck('id')->all();

        $response = $this->actingAs($user)->get(route('cuti.laporan.excel', $filters));

        $response->assertOk()->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertMatchesRegularExpression(
            '/attachment; filename=Laporan_Cuti_\d{8}_\d{6}\.xlsx/',
            (string) $response->headers->get('Content-Disposition'),
        );

        $spreadsheet = $this->loadWorkbook($response->streamedContent());
        try {
            $this->assertSame(['Detail Cuti', 'Saldo Cuti'], $spreadsheet->getSheetNames());
            $detail = $spreadsheet->getSheetByName('Detail Cuti');
            $balance = $spreadsheet->getSheetByName('Saldo Cuti');
            $this->assertNotNull($detail);
            $this->assertNotNull($balance);
            $this->assertSame('A2', $detail->getFreezePane());
            $this->assertSame('A2', $balance->getFreezePane());
            $this->assertSame(
                ['No', 'NIP', 'Nama', 'Nama (Aman)', 'Jenis Cuti', 'Tanggal Mulai', 'Tanggal Selesai', 'Hari Kerja', 'Status'],
                $detail->rangeToArray('A1:I1')[0],
            );
            $this->assertSame(
                ['No', 'NIP', 'Nama', 'Tahun', 'Sisa N-2', 'Sisa N-1', 'Sisa Tahun Berjalan', 'Sisa', 'Hangus'],
                $balance->rangeToArray('A1:I1')[0],
            );
            $this->assertSame('I', $detail->getHighestDataColumn());
            $this->assertSame('I', $balance->getHighestDataColumn());
            $this->assertSame($expectedIds, [$included->id]);
            $this->assertSame(2, $detail->getHighestDataRow());
            $this->assertSame(2, $balance->getHighestDataRow());
            $this->assertSame("'  +SUM(A1:A2)", $detail->getCell('C2')->getValue());
            $this->assertSame("'=1+1", $detail->getCell('B2')->getValue());
            $this->assertSame("'  +SUM(A1:A2)", $detail->getCell('D2')->getValue());
            $this->assertSame("'@Cuti Excel", $detail->getCell('E2')->getValue());
            $this->assertSame('Menunggu Approver', $detail->getCell('I2')->getValue());
            foreach (['B2', 'C2', 'D2', 'E2', 'I2'] as $cell) {
                $this->assertSame(DataType::TYPE_STRING, $detail->getCell($cell)->getDataType());
            }
            $this->assertSame(Date::PHPToExcel($included->tanggal_mulai), $detail->getCell('F2')->getValue());
            $this->assertSame(Date::PHPToExcel($included->tanggal_selesai), $detail->getCell('G2')->getValue());
            $this->assertSame('yyyy-mm-dd', $detail->getStyle('F2')->getNumberFormat()->getFormatCode());
            $this->assertSame('yyyy-mm-dd', $detail->getStyle('G2')->getNumberFormat()->getFormatCode());
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
            $this->assertSame("'  -Nama Berbahaya", $detail->getCell('D2')->getValue());
            $this->assertSame("'\n@Jenis Berbahaya", $detail->getCell('E2')->getValue());
            $this->assertSame("Menunggu \n+Verifikator", $detail->getCell('I2')->getValue());
            $this->assertSame("'\t=1980", $balance->getCell('B2')->getValue());
            $this->assertSame("'  -Nama Berbahaya", $balance->getCell('C2')->getValue());
            foreach (['B2', 'C2', 'D2', 'E2', 'I2'] as $cell) {
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
        foreach (array_chunk(range(1, 5001), 500) as $indexes) {
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

    private function createLeaveRequest(Employee $employee, RefJenisCuti $jenis, string $date): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id, 'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => $date, 'tanggal_selesai' => $date,
            'jumlah_hari_kerja' => 1, 'alasan' => 'Data Excel', 'status' => 'menunggu_approval',
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
}
