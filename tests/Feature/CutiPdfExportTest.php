<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Queries\Cuti\CutiRekapQuery;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class CutiPdfExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_konfigurasi_dompdf_dikeraskan_untuk_ekspor_cuti(): void
    {
        $expectedChroot = realpath(base_path());

        $this->assertNotFalse($expectedChroot);
        $this->assertSame('a4', config('dompdf.options.default_paper_size'));
        $this->assertSame('portrait', config('dompdf.options.default_paper_orientation'));
        $this->assertFalse(config('dompdf.options.enable_remote'));
        $this->assertFalse(config('dompdf.options.enable_php'));
        $this->assertFalse(config('dompdf.options.enable_javascript'));
        $this->assertTrue(config('dompdf.options.enable_html5_parser'));
        $this->assertSame($expectedChroot, config('dompdf.options.chroot'));
    }

    public function test_pdf_adalah_attachment_nyata_dengan_data_terfilter_dan_teks_hostile_tidak_dieksekusi(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create([
            'nip' => '198001012000011001',
            'nama_lengkap' => '<script>alert("pdf")</script> Nama Aman',
            'jabatan_terakhir' => 'Bagian PDF',
        ]);
        $pegawaiLain = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti PDF']);
        $this->createLeaveRequest($pegawai, $jenis, '2026-06-10');
        $this->createLeaveRequest($pegawaiLain, $jenis, '2026-06-11');

        $response = $this->actingAs($user)->get(route('cuti.laporan.pdf', [
            'pegawai' => $pegawai->id,
            'periode' => '2026-06',
        ]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression(
            '/attachment; filename=Rekap_Cuti_[\w\-]+_\d{8}\.pdf/',
            (string) $response->headers->get('Content-Disposition'),
        );
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertSame('a4', config('dompdf.options.default_paper_size'));
        $this->assertSame('portrait', config('dompdf.options.default_paper_orientation'));
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $content);
        preg_match('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $content, $mediaBox);
        $width = (float) $mediaBox[1];
        $height = (float) $mediaBox[2];
        $this->assertEqualsWithDelta(841.89, $width, 1.0);
        $this->assertEqualsWithDelta(595.28, $height, 1.0);
        $this->assertGreaterThan($height, $width);
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringNotContainsString('javascript:', strtolower($content));

        $query = app(CutiRekapQuery::class);
        $detailRows = $query->allDetailRows(['pegawai' => $pegawai->id]);
        $html = view('admin.cuti.pdf.laporan-cuti', [
            'rows' => $detailRows,
            'summaryRows' => $query->summaryRows([]),
            'periodLabel' => $query->periodLabel([]),
            'filters' => [],
            'generatedAt' => now(),
        ])->render();
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;pdf&quot;)&lt;/script&gt; Nama Aman', $html);
        $this->assertStringNotContainsString('<script>alert("pdf")</script>', $html);
    }

    public function test_template_pdf_memuat_identitas_kolom_periode_tanda_tangan_dan_footer_resmi(): void
    {
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Kontrak PDF']);
        $this->createLeaveRequest($pegawai, $jenis, '2026-06-10');
        $query = app(CutiRekapQuery::class);
        $rows = $query->allDetailRows(['pegawai' => $pegawai->id]);

        $filters = ['periode' => 'Juni 2026'];
        $html = view('admin.cuti.pdf.laporan-cuti', [
            'rows' => $rows,
            'summaryRows' => $query->summaryRows($filters),
            'periodLabel' => $query->periodLabel($filters),
            'filters' => $filters,
            'generatedAt' => now(),
        ])->render();

        foreach ([
            'Lembaga Layanan Pendidikan Tinggi (LLDIKTI) Wilayah XVI',
            'Rekap Cuti Pegawai',
            'Periode: 2026-06',
            '>No</th>',
            '>NIP</th>',
            '>Nama Pegawai</th>',
            '>Jenis Cuti</th>',
            'Pembuat Laporan',
            'Mengetahui',
            'Dokumen dibuat pada',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        // Footer bernomor halaman memakai counter CSS; total halaman tidak ditampilkan
        // karena counter(pages) Dompdf merender nilai nol pada dokumen multi-halaman.
        $this->assertStringContainsString('<footer>', $html);
        $this->assertStringContainsString('class="footer-table"', $html);
        $this->assertStringContainsString('class="footer-generated"', $html);
        $this->assertStringContainsString('class="footer-page"', $html);
        $this->assertStringContainsString('class="page-number"', $html);
        $this->assertStringContainsString('counter(page)', $html);
        $this->assertStringNotContainsString('class="total-pages"', $html);
        $this->assertStringNotContainsString('counter(pages)', $html);
        $this->assertStringNotContainsString('float:right', $html);
        $this->assertStringNotContainsString('.footer-page { position:', $html);
        $this->assertStringNotContainsString('text/php', $html);
        $this->assertStringContainsString('<img', $html); // Logo LLDIKTI
        $this->assertStringNotContainsString('<th>Mulai</th>', $html); // Detail pengajuan dihapus
        $this->assertStringNotContainsString('<th>Selesai</th>', $html);
        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
    }

    public function test_pdf_menyediakan_rekap_agregasi_per_pegawai_untuk_template(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create(['nip' => '198203032008041002', 'nama_lengkap' => 'Pegawai Rekap PDF']);
        $tahunan = RefJenisCuti::create(['nama' => 'Cuti Tahunan']);
        $sakit = RefJenisCuti::create(['nama' => 'Cuti Sakit']);
        $approvedTahunanA = $this->createLeaveRequest($pegawai, $tahunan, '2026-06-02', 'disetujui', 3);
        $approvedTahunanB = $this->createLeaveRequest($pegawai, $tahunan, '2026-06-09', 'disetujui', 2);
        $approvedSakit = $this->createLeaveRequest($pegawai, $sakit, '2026-06-16', 'disetujui', 1);
        $this->createApprovedFact($pegawai, $tahunan, $approvedTahunanA, 3);
        $this->createApprovedFact($pegawai, $tahunan, $approvedTahunanB, 2);
        $this->createApprovedFact($pegawai, $sakit, $approvedSakit, 1);
        $this->createLeaveRequest($pegawai, $tahunan, '2026-06-23', 'menunggu_approval', 8);
        LeaveBalance::create(['employee_id' => $pegawai->id, 'tahun' => 2026, 'sisa' => 9]);

        $captured = null;
        View::composer('admin.cuti.pdf.laporan-cuti', function ($view) use (&$captured): void {
            $captured ??= $view->getData();
        });

        $this->actingAs($user)->get(route('cuti.laporan.pdf', ['pegawai' => $pegawai->id, 'periode' => '2026-06']));

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('summaryRows', $captured);
        $summary = collect($captured['summaryRows'])->keyBy('jenis');
        $this->assertCount(2, $summary);
        $this->assertSame(5, $summary['Cuti Tahunan']['total_hari']);
        $this->assertSame(1, $summary['Cuti Sakit']['total_hari']);
        $this->assertSame(9, $summary['Cuti Tahunan']['sisa_saldo']);
        $this->assertSame('198203032008041002', $summary['Cuti Tahunan']['nip']);
        $this->assertSame('Pegawai Rekap PDF', $summary['Cuti Tahunan']['nama']);
        $this->assertSame(2026, $summary['Cuti Tahunan']['saldo_tahun']);
        $this->assertSame('2026-06', $captured['periodLabel']);
    }

    public function test_pdf_memuat_detail_sumber_aman_dan_ringkasan_hanya_fakta_aktif(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create([
            'nip' => '198203032008041009',
            'nama_lengkap' => '<b>Pegawai Source PDF</b>',
            'jabatan_terakhir' => 'Unit PDF Source',
        ]);
        $jenis = RefJenisCuti::create(['nama' => 'Cuti Source PDF']);
        $request = $this->createLeaveRequest($pegawai, $jenis, '2026-06-10', 'disetujui', 2);
        $this->createUsage($pegawai, $jenis, [
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'leave_request_id' => $request->id,
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
            'administrative_note' => 'RAHASIA_PDF_MANUAL',
        ]);
        $this->createUsage($pegawai, $jenis, [
            'start_date' => '2026-06-12',
            'end_date' => '2026-06-12',
            'effective_date' => '2026-06-12',
            'workdays' => 7,
            'record_status' => LeaveUsageRecord::STATUS_CANCELLED,
            'correction_reason' => 'Dibatalkan dan tidak boleh masuk PDF.',
        ]);

        $captured = null;
        View::composer('admin.cuti.pdf.laporan-cuti', function ($view) use (&$captured): void {
            $captured ??= $view->getData();
        });

        $this->actingAs($user)->get(route('cuti.laporan.pdf', [
            'pegawai' => $pegawai->id,
            'periode' => '2026-06',
        ]))->assertOk();

        $this->assertIsArray($captured);
        $this->assertCount(2, $captured['rows']);
        $this->assertSame(5, collect($captured['summaryRows'])->sole()['total_hari']);
        $html = view('admin.cuti.pdf.laporan-cuti', $captured)->render();
        $this->assertStringContainsString('Sumber', $html);
        $this->assertStringContainsString('Melalui SIMPEG', $html);
        $this->assertStringContainsString('Di luar SIMPEG', $html);
        $this->assertStringContainsString('Disetujui melalui SIMPEG', $html);
        $this->assertStringContainsString('Disetujui di luar SIMPEG', $html);
        $this->assertStringContainsString('&lt;b&gt;Pegawai Source PDF&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Pegawai Source PDF</b>', $html);
        $this->assertStringNotContainsString('RAHASIA_PDF_MANUAL', $html);
    }

    public function test_pdf_menolak_501_baris_tanpa_truncation(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti PDF Batas']);
        $now = now();
        $rows = [];
        foreach (range(1, 500) as $index) {
            $rows[] = [
                'id' => fake()->uuid(),
                'employee_id' => $pegawai->id,
                'jenis_cuti_id' => $jenis->id,
                'tanggal_mulai' => '2026-06-10',
                'tanggal_selesai' => '2026-06-10',
                'jumlah_hari_kerja' => 1,
                'alasan' => 'Batas PDF '.$index,
                'status' => 'disetujui',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        LeaveRequest::query()->insert($rows);
        $this->createUsage($pegawai, $jenis, [
            'start_date' => '2026-06-11',
            'end_date' => '2026-06-11',
            'effective_date' => '2026-06-11',
        ]);

        $response = $this->actingAs($user)
            ->from(route('cuti.laporan'))
            ->get(route('cuti.laporan.pdf', ['pegawai' => $pegawai->id]));

        $response->assertRedirect(route('cuti.laporan'));
        $response->assertSessionHas('error', fn (string $message): bool => str_contains($message, '501')
            && str_contains($message, '500')
            && str_contains(strtolower($message), 'persempit filter'));
    }

    public function test_pdf_menolak_total_501_baris_detail_dan_ringkasan_tanpa_truncation(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $tahunan = RefJenisCuti::create([
            'code' => RefJenisCuti::CODE_TAHUNAN,
            'nama' => 'Cuti Tahunan Batas PDF',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $now = now();
        $details = [];

        // 499 pengajuan SIMPEG + 1 fakta manual = 500 detail.
        // Satu baris ringkasan fakta menghasilkan total ekspor 501.
        foreach (range(1, 499) as $index) {
            $details[] = [
                'id' => fake()->uuid(),
                'employee_id' => $pegawai->id,
                'jenis_cuti_id' => $tahunan->id,
                'tanggal_mulai' => '2026-06-10',
                'tanggal_selesai' => '2026-06-10',
                'jumlah_hari_kerja' => 1,
                'alasan' => 'Detail batas gabungan PDF '.$index,
                'status' => 'disetujui',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        LeaveRequest::query()->insert($details);
        // Ringkasan tahunan hanya membaca fakta pemakaian final aktif.
        // Saldo material tidak lagi menjadi sumber agregat laporan.
        $this->createUsage($pegawai, $tahunan, [
            'start_date' => '2026-06-11',
            'end_date' => '2026-06-11',
            'effective_date' => '2026-06-11',
        ]);

        $filters = ['pegawai' => $pegawai->id, 'periode' => '2026'];
        $query = app(CutiRekapQuery::class);

        $this->assertSame(500, $query->detailCount($filters));
        $this->assertCount(1, $query->summaryRows($filters));

        $response = $this->actingAs($user)
            ->from(route('cuti.laporan', $filters))
            ->get(route('cuti.laporan.pdf', $filters));

        $response->assertRedirect(route('cuti.laporan', $filters));
        $response->assertSessionHas('error', fn (string $message): bool => str_contains($message, '501')
            && str_contains($message, '500')
            && str_contains(strtolower($message), 'persempit filter'));
    }

    private function createLeaveRequest(
        Employee $employee,
        RefJenisCuti $jenis,
        string $date,
        string $status = 'disetujui',
        int $hari = 1,
    ): LeaveRequest {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => $date,
            'tanggal_selesai' => $date,
            'jumlah_hari_kerja' => $hari,
            'alasan' => 'Data PDF',
            'status' => $status,
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
            'leave_request_id' => null,
            'leave_request_case_id' => null,
            'usage_year' => 2026,
            'effective_date' => '2026-06-15',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'workdays' => 1,
            'administrative_note' => 'Fixture PDF source-aware.',
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
}
