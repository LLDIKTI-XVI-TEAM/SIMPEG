<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class EmployeeReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);
    }

    public function test_guest_and_unauthorized_role_cannot_access_employee_export(): void
    {
        $this->get(route('laporan.pegawai'))->assertRedirect(route('login'));
        $this->get(route('laporan.pegawai.preview'))->assertRedirect(route('login'));

        $pegawai = User::factory()->pegawai()->create();

        $this->actingAs($pegawai)
            ->get(route('laporan.pegawai'))
            ->assertForbidden();

        $this->actingAs($pegawai)
            ->get(route('laporan.pegawai.preview'))
            ->assertForbidden();
    }

    public function test_preview_and_standard_excel_use_real_active_employee_data_and_same_filter(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $unitKepegawaian = RefUnitKerja::query()
            ->where('nama', 'Urusan Organisasi Tata Laksana dan SDM')
            ->firstOrFail();
        $unitLain = RefUnitKerja::query()->where('nama', 'Urusan Keuangan')->firstOrFail();

        $included = $this->createEmployee($unitKepegawaian, [
            'nama_lengkap' => 'Ahmad Export',
            'nip' => '198503122010011001',
            'jabatan_terakhir' => 'Analis Kepegawaian',
            'golongan_terakhir' => 'III/c',
        ]);
        $this->createEmployee($unitLain, [
            'nama_lengkap' => 'Bukan Hasil Filter',
            'nip' => '198503122010011002',
        ]);
        $this->createEmployee($unitKepegawaian, [
            'nama_lengkap' => 'Pegawai Pensiun',
            'nip' => '198503122010011003',
            'status_aktif' => 'Pensiun',
            'status_pegawai_id' => RefStatusPegawai::query()->where('nama', 'Pensiun')->value('id'),
        ]);

        $this->actingAs($admin)
            ->get(route('laporan.pegawai'))
            ->assertOk()
            ->assertSee('Ahmad Export')
            ->assertDontSee('Pegawai Pensiun');

        $this->actingAs($admin)
            ->getJson(route('laporan.pegawai.preview'))
            ->assertOk()
            ->assertJsonCount(3, 'pegawai')
            ->assertJsonMissing(['nama' => 'Pegawai Pensiun']);

        $this->actingAs($admin)
            ->getJson(route('laporan.pegawai.preview', ['status' => '']))
            ->assertOk()
            ->assertJsonCount(4, 'pegawai')
            ->assertJsonFragment(['nama' => 'Pegawai Pensiun']);

        $response = $this->actingAs($admin)->get(route('laporan.pegawai.excel', [
            'unit' => $unitKepegawaian->nama,
            'sort' => 'nama',
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(
            'Daftar_Pegawai_LLDIKTI_XVI_'.now()->format('Ymd').'.xlsx',
            (string) $response->headers->get('Content-Disposition')
        );

        $spreadsheet = $this->loadSpreadsheet($response->streamedContent());

        try {
            $sheet = $spreadsheet->getActiveSheet();

            $this->assertSame('Daftar Nominatif Pegawai', $sheet->getTitle());
            $this->assertSame('No', $sheet->getCell('A1')->getValue());
            $this->assertSame('NIP', $sheet->getCell('B1')->getValue());
            $this->assertSame('Nama', $sheet->getCell('C1')->getValue());
            $this->assertSame('Golongan', $sheet->getCell('D1')->getValue());
            $this->assertSame('Jabatan', $sheet->getCell('E1')->getValue());
            $this->assertSame('Unit Kerja', $sheet->getCell('F1')->getValue());
            $this->assertSame('Jenis Pegawai', $sheet->getCell('G1')->getValue());
            $this->assertSame('Status', $sheet->getCell('H1')->getValue());
            $this->assertSame($included->nip, $sheet->getCell('B2')->getValue());
            $this->assertSame('Ahmad Export', $sheet->getCell('C2')->getValue());
            $this->assertSame('Analis Kepegawaian', $sheet->getCell('E2')->getValue());
            $this->assertSame('Urusan Organisasi Tata Laksana dan SDM', $sheet->getCell('F2')->getValue());
            $this->assertSame('A2', $sheet->getFreezePane());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_preview_and_standard_export_reject_contact_data(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $this->createEmployee(
            RefUnitKerja::query()
                ->where('nama', 'Urusan Organisasi Tata Laksana dan SDM')
                ->firstOrFail(),
            [
                'nama_lengkap' => 'Pegawai Tanpa Kontak Laporan',
                'nip' => '198503122010011005',
                'email_pribadi' => 'pii-preview-unique@example.test',
                'no_hp' => '081234567890',
            ],
        );

        $this->actingAs($admin)
            ->get(route('laporan.pegawai'))
            ->assertOk()
            ->assertDontSee('pii-preview-unique@example.test', false)
            ->assertDontSee('081234567890', false)
            ->assertDontSee('"email"', false)
            ->assertDontSee('"no_hp"', false);

        $this->actingAs($admin)
            ->getJson(route('laporan.pegawai.preview'))
            ->assertOk()
            ->assertJsonMissing(['email_pribadi' => 'pii-preview-unique@example.test'])
            ->assertJsonMissing(['no_hp' => '081234567890']);

        $this->actingAs($admin)
            ->from(route('laporan.pegawai'))
            ->get(route('laporan.pegawai.excel', [
                'columns' => ['nama', 'email', 'no_hp'],
            ]))
            ->assertRedirect(route('laporan.pegawai'))
            ->assertSessionHasErrors(['columns.1', 'columns.2']);
    }

    public function test_preview_preserves_export_configuration_in_initial_filter_state(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)
            ->get(route('laporan.pegawai', [
                'jabatan' => 'Analis Kepegawaian',
                'pensiun_dari' => '2030-01-01',
                'pensiun_sampai' => '2040-12-31',
                'sort' => 'nip',
                'sort_dir' => 'desc',
                'prefix_field' => 'nip',
                'prefix_value' => '1985',
                'row_start' => 2,
                'row_end' => 5,
            ]))
            ->assertOk()
            ->assertViewHas('initialFilters', function (array $filters): bool {
                return $filters['jabatan'] === 'Analis Kepegawaian'
                    && $filters['pensiun_dari'] === '2030-01-01'
                    && $filters['pensiun_sampai'] === '2040-12-31'
                    && $filters['sort'] === 'nip'
                    && $filters['sort_dir'] === 'desc'
                    && $filters['prefix_field'] === 'nip'
                    && $filters['prefix_value'] === '1985'
                    && $filters['row_start'] === 2
                    && $filters['row_end'] === 5;
            });
    }

    public function test_pdf_print_is_blocked_when_preview_is_not_current(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)
            ->get(route('laporan.pegawai'))
            ->assertOk()
            ->assertSee('x-bind:disabled="previewLoading || !!previewError || !!pensiunError"', false)
            ->assertSee('if (this.previewLoading || this.previewError || this.pensiunError)', false);
    }

    public function test_preview_applies_initial_row_range_once_on_backend(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $unit = RefUnitKerja::query()
            ->where('nama', 'Urusan Organisasi Tata Laksana dan SDM')
            ->firstOrFail();

        foreach (range(1, 5) as $number) {
            $this->createEmployee($unit, [
                'nama_lengkap' => "Pegawai Rentang {$number}",
                'nip' => sprintf('198503122010011%03d', $number),
            ]);
        }

        $this->actingAs($admin)
            ->get(route('laporan.pegawai', [
                'status' => 'Aktif',
                'search' => 'Pegawai Rentang',
                'sort' => 'nip',
                'row_start' => 2,
                'row_end' => 5,
            ]))
            ->assertOk()
            ->assertViewHas('pegawai', function (array $pegawai): bool {
                return array_column($pegawai, 'nip') === [
                    '198503122010011002',
                    '198503122010011003',
                    '198503122010011004',
                    '198503122010011005',
                ];
            })
            ->assertViewHas('initialFilters', fn (array $filters): bool => $filters['row_start'] === 2 && $filters['row_end'] === 5);
    }

    public function test_preview_endpoint_can_restore_rows_after_a_filter_is_cleared(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $unit = RefUnitKerja::query()
            ->where('nama', 'Urusan Organisasi Tata Laksana dan SDM')
            ->firstOrFail();
        $analyst = $this->createEmployee($unit, [
            'nama_lengkap' => 'Pegawai Analis',
            'nip' => '198503122010011011',
            'jabatan_terakhir' => 'Analis Kepegawaian',
        ]);
        $dataManager = $this->createEmployee($unit, [
            'nama_lengkap' => 'Pegawai Pengelola',
            'nip' => '198503122010011012',
            'jabatan_terakhir' => 'Pengelola Data',
        ]);

        $this->actingAs($admin)
            ->getJson(route('laporan.pegawai.preview', [
                'status' => 'Aktif',
                'search' => 'Pegawai',
                'jabatan' => 'Analis Kepegawaian',
                'sort' => 'nip',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'pegawai')
            ->assertJsonPath('pegawai.0.nip', $analyst->nip);

        $this->actingAs($admin)
            ->getJson(route('laporan.pegawai.preview', [
                'status' => 'Aktif',
                'search' => 'Pegawai',
                'sort' => 'nip',
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'pegawai')
            ->assertJsonPath('pegawai.0.nip', $analyst->nip)
            ->assertJsonPath('pegawai.1.nip', $dataManager->nip);
    }

    public function test_preview_endpoint_and_custom_export_use_the_same_sorted_range(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $unit = RefUnitKerja::query()
            ->where('nama', 'Urusan Organisasi Tata Laksana dan SDM')
            ->firstOrFail();

        $this->createEmployee($unit, [
            'nama_lengkap' => 'A b',
            'nip' => '198503122010011021',
        ]);
        $this->createEmployee($unit, [
            'nama_lengkap' => 'A-b',
            'nip' => '198503122010011022',
        ]);
        $this->createEmployee($unit, [
            'nama_lengkap' => 'A2',
            'nip' => '198503122010011023',
        ]);

        $filters = [
            'status' => 'Aktif',
            'sort' => 'nama',
            'row_start' => 2,
            'row_end' => 2,
        ];
        $preview = $this->actingAs($admin)
            ->getJson(route('laporan.pegawai.preview', $filters))
            ->assertOk()
            ->assertJsonCount(1, 'pegawai');

        $response = $this->actingAs($admin)->post(route('laporan.pegawai.custom'), [
            ...$filters,
            'columns' => ['nama'],
        ]);
        $response->assertOk();

        $spreadsheet = $this->loadSpreadsheet($response->streamedContent());

        try {
            $this->assertSame($preview->json('pegawai.0.nama'), $spreadsheet->getActiveSheet()->getCell('A2')->getValue());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_custom_export_preserves_user_column_order_and_rejects_sensitive_columns(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = $this->createEmployee(
            RefUnitKerja::query()
                ->where('nama', 'Urusan Organisasi Tata Laksana dan SDM')
                ->firstOrFail(),
            [
                'nama_lengkap' => 'Nadia Custom',
                'nip' => '198503122010011004',
                'tanggal_pensiun' => '2038-08-17',
            ],
        );

        // Kirim kolom dalam urutan: tanggal_pensiun → nama → nip.
        // Excel harus mencerminkan urutan tersebut persis (bukan urutan baku).
        $response = $this->actingAs($admin)->post(route('laporan.pegawai.custom'), [
            'columns' => ['tanggal_pensiun', 'nama', 'nip'],
            'status' => 'Aktif',
            'search' => 'Nadia Custom',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $spreadsheet = $this->loadSpreadsheet($response->streamedContent());

        try {
            $sheet = $spreadsheet->getActiveSheet();

            // Urutan kolom mengikuti input user: A=Tanggal Pensiun, B=Nama, C=NIP.
            $this->assertSame('Tanggal Pensiun', $sheet->getCell('A1')->getValue());
            $this->assertSame('Nama', $sheet->getCell('B1')->getValue());
            $this->assertSame('NIP', $sheet->getCell('C1')->getValue());
            $this->assertSame('2038-08-17', $sheet->getCell('A2')->getValue());
            $this->assertSame('Nadia Custom', $sheet->getCell('B2')->getValue());
            $this->assertSame($employee->nip, $sheet->getCell('C2')->getValue());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $this->actingAs($admin)
            ->from(route('laporan.pegawai'))
            ->post(route('laporan.pegawai.custom'), [
                'columns' => ['nik', 'email', 'no_hp'],
            ])
            ->assertRedirect(route('laporan.pegawai'))
            ->assertSessionHasErrors(['columns.0', 'columns.1', 'columns.2']);
    }

    /** @param array<string, mixed> $overrides */
    private function createEmployee(RefUnitKerja $unit, array $overrides = []): Employee
    {
        $jenis = RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail();
        $status = RefStatusPegawai::query()->where('nama', $overrides['status_aktif'] ?? 'Aktif')->firstOrFail();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $jenis->id,
            'status_pegawai_id' => $status->id,
            'status_aktif' => $status->nama,
            ...$overrides,
        ]);

        PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => $employee->jabatan_terakhir,
            'jenis_jabatan_id' => RefJenisJabatan::query()->firstOrFail()->id,
            'unit_kerja_id' => $unit->id,
            'no_sk' => 'SK-EXPORT-'.substr($employee->nip, -6),
            'tanggal_sk' => '2020-01-01',
            'tmt_jabatan' => '2020-01-01',
            'is_latest' => true,
        ]);

        return $employee;
    }

    private function loadSpreadsheet(string $content): Spreadsheet
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'simpeg-employee-report-');
        file_put_contents($temporaryFile, $content);

        try {
            return IOFactory::load($temporaryFile);
        } finally {
            @unlink($temporaryFile);
        }
    }
}
