<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class PimpinanLeaveReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_pimpinan_report_landing_uses_the_report_page_title(): void
    {
        $this->seed(RbacSeeder::class);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.laporan.index'))
            ->assertOk()
            ->assertSee('<title>Laporan &amp; Statistik — SIMPEG</title>', false);
    }

    public function test_pimpinan_preview_and_excel_export_use_filtered_leave_requests(): void
    {
        $this->seed(RbacSeeder::class);

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Citra Wulandari',
            'nip' => '198303032009032003',
        ]);
        $leaveType = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'CUTI_TAHUNAN',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'disetujui',
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2025-07-06',
            'tanggal_selesai' => '2025-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Data periode lain.',
            'status' => 'disetujui',
        ]);

        $user = User::factory()->pimpinan()->create();

        $this->actingAs($user)
            ->get(route('pimpinan.laporan.cuti', ['tahun' => 2026, 'bulan' => 7]))
            ->assertOk()
            ->assertSee('Citra Wulandari')
            ->assertSee('06 Jul 2026')
            ->assertDontSee('06 Jul 2025');

        $response = $this->actingAs($user)->get(route('pimpinan.laporan.cuti.excel', [
            'tahun' => 2026,
            'bulan' => 7,
        ]));

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $temporaryFile = tempnam(sys_get_temp_dir(), 'simpeg-pimpinan-cuti-');
        file_put_contents($temporaryFile, $response->streamedContent());
        $spreadsheet = null;

        try {
            $spreadsheet = IOFactory::load($temporaryFile);
            $detailSheet = $spreadsheet->getSheetByName('Detail Cuti');

            $this->assertNotNull($detailSheet);
            $this->assertSame('Citra Wulandari', $detailSheet->getCell('C2')->getValue());
            $this->assertSame('2026-07-06', $detailSheet->getCell('E2')->getValue());
            $this->assertNull($detailSheet->getCell('C3')->getValue());
        } finally {
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }

            @unlink($temporaryFile);
        }
    }
}
