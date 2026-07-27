<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
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
            '/attachment; filename=Laporan_Cuti_\d{8}_\d{6}\.pdf/',
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
        $this->assertEqualsWithDelta(595.28, $width, 1.0);
        $this->assertEqualsWithDelta(841.89, $height, 1.0);
        $this->assertGreaterThan($width, $height);
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringNotContainsString('javascript:', strtolower($content));

        $detailRows = (new CutiRekapQuery)->detailRows(['pegawai' => $pegawai->id])->get();
        $html = view('admin.cuti.pdf.laporan-cuti', [
            'rows' => $detailRows,
            'summaryRows' => (new CutiRekapQuery)->summaryRows($detailRows, []),
            'periodLabel' => (new CutiRekapQuery)->periodLabel([]),
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
        $rows = (new CutiRekapQuery)->detailRows(['pegawai' => $pegawai->id])->get();
        $rows->each(fn (LeaveRequest $row) => $row->setAttribute('report_status', 'Disetujui'));

        $filters = ['periode' => 'Juni 2026'];
        $html = view('admin.cuti.pdf.laporan-cuti', [
            'rows' => $rows,
            'summaryRows' => (new CutiRekapQuery)->summaryRows($rows, $filters),
            'periodLabel' => (new CutiRekapQuery)->periodLabel($filters),
            'filters' => $filters,
            'generatedAt' => now(),
        ])->render();

        foreach ([
            'LEMBAGA LAYANAN PENDIDIKAN TINGGI WILAYAH XVI',
            'Rekap Cuti Pegawai',
            'Periode: Juni 2026',
            '<th>No</th>',
            '<th>NIP</th>',
            '<th>Nama</th>',
            '<th>Jenis</th>',
            '<th>Mulai</th>',
            '<th>Selesai</th>',
            '<th>Hari Kerja</th>',
            '<th>Status</th>',
            'Pembuat Laporan',
            'Mengetahui',
            'Dokumen dibuat pada',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
    }

    public function test_pdf_menyediakan_rekap_agregasi_per_pegawai_untuk_template(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create(['nip' => '198203032008041002', 'nama_lengkap' => 'Pegawai Rekap PDF']);
        $tahunan = RefJenisCuti::create(['nama' => 'Cuti Tahunan']);
        $sakit = RefJenisCuti::create(['nama' => 'Cuti Sakit']);
        $this->createLeaveRequest($pegawai, $tahunan, '2026-06-02', 'disetujui', 3);
        $this->createLeaveRequest($pegawai, $tahunan, '2026-06-09', 'disetujui', 2);
        $this->createLeaveRequest($pegawai, $sakit, '2026-06-16', 'disetujui', 1);
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

    public function test_pdf_menolak_501_baris_tanpa_truncation(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();
        $jenis = RefJenisCuti::create(['nama' => 'Cuti PDF Batas']);
        $now = now();
        $rows = [];
        foreach (range(1, 501) as $index) {
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

        $response = $this->actingAs($user)
            ->from(route('cuti.laporan'))
            ->get(route('cuti.laporan.pdf', ['pegawai' => $pegawai->id]));

        $response->assertRedirect(route('cuti.laporan'));
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
}
