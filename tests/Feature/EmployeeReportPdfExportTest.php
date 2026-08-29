<?php

namespace Tests\Feature;

use App\Actions\Laporan\ExportPegawaiPdfAction;
use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use App\Models\User;
use App\Services\Laporan\EmployeeExportDataService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Js;
use Tests\TestCase;

/**
 * Mengunci perilaku export PDF daftar pegawai: gerbang peran, penerusan filter
 * dan range, bentuk respons attachment, batas baris, serta larangan data sensitif
 * pada berkas hasil.
 */
class EmployeeReportPdfExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);
    }

    public function test_guest_ditolak_dari_rute_export_pdf(): void
    {
        $this->get(route('laporan.pegawai.pdf'))->assertRedirect(route('login'));
    }

    public function test_role_tanpa_wewenang_ditolak_dari_rute_export_pdf(): void
    {
        $this->actingAs(User::factory()->pegawai()->create())
            ->get(route('laporan.pegawai.pdf'))
            ->assertForbidden();
    }

    public function test_admin_kepegawaian_dan_pimpinan_mengunduh_attachment_pdf(): void
    {
        $this->createEmployee($this->unitKepegawaian(), [
            'nama_lengkap' => 'Pegawai Unduh PDF',
            'nip' => '198503122010012001',
        ]);

        foreach ([
            User::factory()->adminKepegawaian()->create(),
            User::factory()->pimpinan()->create(),
        ] as $user) {
            $response = $this->actingAs($user)->get(route('laporan.pegawai.pdf'));

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertSame(
                'attachment; filename=Laporan_Pegawai_'.now()->format('Ymd').'.pdf',
                (string) $response->headers->get('Content-Disposition'),
            );
            $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        }
    }

    public function test_pdf_memakai_orientasi_landscape(): void
    {
        $this->createEmployee($this->unitKepegawaian(), ['nip' => '198503122010012009']);

        $content = (string) $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('laporan.pegawai.pdf'))
            ->assertOk()
            ->getContent();

        preg_match('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $content, $mediaBox);
        $this->assertNotEmpty($mediaBox, 'MediaBox tidak ditemukan pada berkas PDF.');
        $this->assertGreaterThan((float) $mediaBox[2], (float) $mediaBox[1], 'PDF seharusnya landscape.');
    }

    public function test_filter_dan_range_aktif_diteruskan_ke_template_pdf(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $unitTarget = $this->unitKepegawaian();
        $unitLain = RefUnitKerja::query()->where('nama', 'Urusan Keuangan')->firstOrFail();

        foreach (range(1, 4) as $number) {
            $this->createEmployee($unitTarget, [
                'nama_lengkap' => "Pegawai Filter {$number}",
                'nip' => sprintf('19850312201001200%d', $number),
            ]);
        }
        $this->createEmployee($unitLain, [
            'nama_lengkap' => 'Pegawai Unit Lain',
            'nip' => '198503122010012099',
        ]);

        $captured = null;
        View::composer('admin.laporan.pdf-pegawai', function ($view) use (&$captured): void {
            $captured ??= $view->getData();
        });

        $this->actingAs($admin)
            ->get(route('laporan.pegawai.pdf', [
                'unit' => $unitTarget->nama,
                'status' => 'Aktif',
                'sort' => 'nip',
                'sort_dir' => 'asc',
                'row_start' => 2,
                'row_end' => 3,
            ]))
            ->assertOk();

        $this->assertIsArray($captured);
        $rows = collect($captured['rows']);

        // Filter unit membuang pegawai unit lain, range baris 2-3 menyisakan dua baris berurutan.
        $this->assertSame(['198503122010012002', '198503122010012003'], $rows->pluck('nip')->all());
        $this->assertNotContains('Pegawai Unit Lain', $rows->pluck('nama')->all());
    }

    public function test_filter_status_kosong_membuka_seluruh_status_dan_default_hanya_aktif(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $unit = $this->unitKepegawaian();

        $this->createEmployee($unit, ['nama_lengkap' => 'Pegawai Aktif PDF', 'nip' => '198503122010012011']);
        $this->createEmployee($unit, [
            'nama_lengkap' => 'Pegawai Pensiun PDF',
            'nip' => '198503122010012012',
            'status_aktif' => 'Pensiun',
            'status_pegawai_id' => RefStatusPegawai::query()->where('nama', 'Pensiun')->value('id'),
        ]);

        $this->assertSame(
            ['Pegawai Aktif PDF'],
            $this->capturedPdfNames($admin, ['search' => 'PDF']),
            'Tanpa parameter status, PDF hanya memuat pegawai aktif.',
        );

        $this->assertEqualsCanonicalizing(
            ['Pegawai Aktif PDF', 'Pegawai Pensiun PDF'],
            $this->capturedPdfNames($admin, ['status' => '', 'search' => 'PDF']),
            'Status kosong berarti semua status ikut diekspor.',
        );
    }

    public function test_pdf_menolak_baris_melebihi_batas_tanpa_pemotongan(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $this->actingAs($admin);
        $jumlah = ExportPegawaiPdfAction::MAX_ROWS + 1;

        // Employee milik aktor autentikasi ikut laporan aktif, sehingga total
        // tetap tepat satu baris di atas batas tanpa membuat fixture berlebih.
        $this->insertBulkActiveEmployees($jumlah - 1);

        $response = $this
            ->from(route('laporan.pegawai'))
            ->get(route('laporan.pegawai.pdf'));

        $response->assertRedirect(route('laporan.pegawai'));
        $response->assertSessionHas('error', fn (string $message): bool => str_contains($message, (string) $jumlah)
            && str_contains($message, (string) ExportPegawaiPdfAction::MAX_ROWS)
            && str_contains(strtolower($message), 'persempit filter'));
    }

    public function test_template_pdf_memuat_kop_judul_tabel_dan_footer_halaman(): void
    {
        $unit = $this->unitKepegawaian();
        $this->createEmployee($unit, [
            'nama_lengkap' => 'Pegawai Template PDF',
            'nip' => '198503122010012021',
            'jabatan_terakhir' => 'Analis Kepegawaian',
        ]);

        $html = view('admin.laporan.pdf-pegawai', [
            'rows' => app(EmployeeExportDataService::class)->rows([]),
        ])->render();

        foreach ([
            'Kementerian Pendidikan Tinggi, Sains, dan Teknologi',
            'Lembaga Layanan Pendidikan Tinggi (LLDIKTI) Wilayah XVI',
            // Judul resmi PDF mengikuti acceptance criteria secara persis.
            'Daftar Pegawai',
            'Tanggal Cetak:',
            '>NIP</th>',
            '>Nama Pegawai</th>',
            '>Golongan</th>',
            '>Jabatan</th>',
            '>Unit Kerja</th>',
            'Pegawai Template PDF',
            'Analis Kepegawaian',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        // Footer bernomor halaman memakai counter CSS karena eksekusi PHP di DOMPDF dimatikan.
        $this->assertStringContainsString('<footer>', $html);
        $this->assertStringContainsString('class="page-number"', $html);
        $this->assertStringContainsString('class="total-pages"', $html);
        $this->assertStringContainsString('counter(page)', $html);
        $this->assertStringContainsString('counter(pages)', $html);
        $this->assertStringNotContainsString('text/php', $html);
        $this->assertStringContainsString('<img', $html);
    }

    public function test_pdf_tidak_memuat_email_nomor_hp_nik_dan_no_kk(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $this->createEmployee($this->unitKepegawaian(), [
            'nama_lengkap' => 'Pegawai Sensitif PDF',
            'nip' => '198503122010012031',
            'email_pribadi' => 'pdf-sensitif-unik@example.test',
            'no_hp' => '081200990011',
            'nik' => '7101010101010001',
            'no_kk' => '7101010101010002',
        ]);

        $html = view('admin.laporan.pdf-pegawai', [
            'rows' => app(EmployeeExportDataService::class)->rows([]),
        ])->render();

        foreach ([
            'pdf-sensitif-unik@example.test',
            '081200990011',
            '7101010101010001',
            '7101010101010002',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $html);
        }

        $content = (string) $this->actingAs($admin)
            ->get(route('laporan.pegawai.pdf'))
            ->assertOk()
            ->getContent();

        // Isi PDF dikompresi, sehingga kebocoran diperiksa pada HTML sumber di atas
        // dan di sini dipastikan berkas tetap terbentuk untuk data yang sama.
        $this->assertStringStartsWith('%PDF-', $content);
    }

    /**
     * Setiap laporan hanya boleh punya satu template PDF resmi. Template duplikat yang
     * tidak terhubung ke rute mana pun pernah menjadi dead code dan membingungkan reviewer.
     */
    public function test_tidak_ada_template_pdf_duplikat_yang_menganggur(): void
    {
        foreach ([
            'pdf.daftar-pegawai',
            'pdf.rekap-cuti',
        ] as $orphan) {
            $this->assertFalse(
                View::exists($orphan),
                "Template {$orphan} tidak terhubung ke rute mana pun dan tidak boleh dihidupkan kembali.",
            );
        }

        $this->assertTrue(View::exists('admin.laporan.pdf-pegawai'));
        $this->assertTrue(View::exists('admin.cuti.pdf.laporan-cuti'));
    }

    public function test_halaman_laporan_memakai_rute_pdf_backend_bukan_cetak_browser(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('laporan.pegawai'))
            ->assertOk()
            // Endpoint disuntikkan lewat @js, sehingga dibandingkan memakai helper yang sama.
            ->assertSee('pdfEndpoint: '.Js::from(route('laporan.pegawai.pdf'))->toHtml(), false)
            ->assertSee('exportPdf()', false)
            ->assertSee('maxPdfRows', false)
            ->assertDontSee('window.print()', false)
            ->assertDontSee('printReport()', false);
    }

    public function test_halaman_laporan_menghubungkan_label_filter_dan_toggle_konfigurasi(): void
    {
        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('laporan.pegawai'))
            ->assertOk();

        foreach ([
            ['filter-search', 'name="search"'],
            ['filter-unit', 'name="unit"'],
            ['filter-golongan', 'name="golongan"'],
            ['filter-jenis', 'name="jenis"'],
            ['filter-status', 'name="status"'],
            ['filter-jabatan', 'name="jabatan"'],
            ['filter-pensiun-dari', 'name="pensiun_dari"'],
            ['filter-pensiun-sampai', 'name="pensiun_sampai"'],
            ['filter-sort', 'name="sort"'],
            ['filter-row-start', 'name="row_start"'],
            ['filter-row-end', 'name="row_end"'],
        ] as [$id, $control]) {
            $response->assertSee('for="'.$id.'"', false);
            $response->assertSee('id="'.$id.'"', false);
            $response->assertSee($control, false);
        }

        $response->assertSee('aria-controls="export-config-panel"', false);
        $response->assertSee('id="export-config-panel"', false);
        $response->assertSee("x-bind:aria-expanded=\"configOpen ? 'true' : 'false'\"", false);
    }

    public function test_tombol_pdf_daftar_pegawai_meneruskan_filter_dan_batas_baris(): void
    {
        $response = $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('data-pegawai'))
            ->assertOk();

        $response->assertSee(route('laporan.pegawai.pdf'), false);
        // Halaman ini menyimpan UUID referensi, jadi meneruskannya lewat parameter id.
        foreach (['unit_kerja_id', 'jenis_pegawai_id', 'status_pegawai_id'] as $param) {
            $response->assertSee($param, false);
        }
        // Batas baris dibaca dari konstanta backend, bukan angka literal di view.
        $response->assertSee('PDF_MAX_ROWS', false);
        $response->assertSee((string) ExportPegawaiPdfAction::MAX_ROWS, false);
    }

    /**
     * Rute PDF menerima filter dalam bentuk UUID referensi karena halaman daftar
     * pegawai menyimpan id, bukan nama.
     */
    public function test_filter_uuid_referensi_diteruskan_ke_pdf(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $unitTarget = $this->unitKepegawaian();
        $unitLain = RefUnitKerja::query()->where('nama', 'Urusan Keuangan')->firstOrFail();
        $pns = RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail();

        $this->createEmployee($unitTarget, [
            'nama_lengkap' => 'Pegawai UUID Target',
            'nip' => '198503122010012041',
        ]);
        $this->createEmployee($unitLain, [
            'nama_lengkap' => 'Pegawai UUID Lain',
            'nip' => '198503122010012042',
        ]);

        $this->assertSame(
            ['Pegawai UUID Target'],
            $this->capturedPdfNames($admin, [
                'unit_kerja_id' => $unitTarget->id,
                'jenis_pegawai_id' => $pns->id,
            ]),
        );
    }

    public function test_status_pegawai_id_kosong_membuka_semua_status(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $unit = $this->unitKepegawaian();

        $this->createEmployee($unit, ['nama_lengkap' => 'Aktif UUID', 'nip' => '198503122010012051']);
        $this->createEmployee($unit, [
            'nama_lengkap' => 'Pensiun UUID',
            'nip' => '198503122010012052',
            'status_aktif' => 'Pensiun',
            'status_pegawai_id' => RefStatusPegawai::query()->where('nama', 'Pensiun')->value('id'),
        ]);

        // Kontrak backend: key status_pegawai_id yang ada walau kosong berarti
        // "semua status" (dipakai konsumen API/laporan; halaman daftar kini justru
        // meng-omit parameternya saat filter 'all' sesuai temuan Codex 24 Agustus,
        // sehingga jatuh ke default aktif dan PDF selaras dengan tabel).
        $this->assertEqualsCanonicalizing(
            ['Aktif UUID', 'Pensiun UUID'],
            $this->capturedPdfNames($admin, ['status_pegawai_id' => '', 'search' => 'UUID']),
        );
    }

    public function test_filter_uuid_tidak_valid_ditolak(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->from(route('data-pegawai'))
            ->get(route('laporan.pegawai.pdf', ['unit_kerja_id' => 'bukan-uuid']))
            ->assertRedirect(route('data-pegawai'))
            ->assertSessionHasErrors('unit_kerja_id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function capturedPdfNames(User $user, array $filters): array
    {
        $captured = null;
        View::composer('admin.laporan.pdf-pegawai', function ($view) use (&$captured): void {
            $captured ??= $view->getData();
        });

        $this->actingAs($user)->get(route('laporan.pegawai.pdf', $filters))->assertOk();

        $this->assertIsArray($captured);

        return collect($captured['rows'])->pluck('nama')->values()->all();
    }

    private function unitKepegawaian(): RefUnitKerja
    {
        return RefUnitKerja::query()
            ->where('nama', 'Urusan Organisasi Tata Laksana dan SDM')
            ->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function createEmployee(RefUnitKerja $unit, array $overrides = []): Employee
    {
        $status = RefStatusPegawai::query()
            ->where('nama', $overrides['status_aktif'] ?? 'Aktif')
            ->firstOrFail();

        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail()->id,
            'status_pegawai_id' => $status->id,
            'status_aktif' => $status->nama,
            ...$overrides,
        ]);

        PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => $employee->jabatan_terakhir,
            'jenis_jabatan_id' => RefJenisJabatan::query()->firstOrFail()->id,
            'unit_kerja_id' => $unit->id,
            'no_sk' => 'SK-PDF-'.substr($employee->nip, -6),
            'tanggal_sk' => '2020-01-01',
            'tmt_jabatan' => '2020-01-01',
            'is_latest' => true,
        ]);

        return $employee;
    }

    /**
     * Menyisipkan banyak pegawai aktif secara massal supaya uji batas baris tetap cepat.
     * Kolom sensitif sengaja dibiarkan kosong karena tidak diperlukan uji ini.
     */
    private function insertBulkActiveEmployees(int $total): void
    {
        $jenisPegawaiId = RefJenisPegawai::query()->where('nama', 'PNS')->value('id');
        $statusPegawaiId = RefStatusPegawai::query()->where('nama', 'Aktif')->value('id');
        $now = now();
        $rows = [];

        foreach (range(1, $total) as $index) {
            $rows[] = [
                'id' => fake()->uuid(),
                'nama_lengkap' => sprintf('Pegawai Batas %04d', $index),
                // Kolom nip dibatasi 18 karakter, jadi prefix 14 digit + urutan 4 digit.
                'nip' => sprintf('19900101202001%04d', $index),
                'jenis_pegawai_id' => $jenisPegawaiId,
                'status_pegawai_id' => $statusPegawaiId,
                'status_aktif' => 'Aktif',
                'golongan_terakhir' => 'III/a',
                'jabatan_terakhir' => 'Analis Kepegawaian',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            Employee::query()->insert($chunk);
        }
    }
}
