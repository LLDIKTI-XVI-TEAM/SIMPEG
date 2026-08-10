<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefJenisPegawai;
use App\Models\SalaryHistory;
use App\Models\User;
use App\Services\Employees\TmtCalculatorService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    private const EMPLOYEE_IMPORT_ENDPOINT = '/api/v1/pegawai/import';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        // Seed RBAC agar permission employees.import tersedia untuk middleware permission.
        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_import_employees(): void
    {
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->csvFile($this->validCsv()),
        ]);

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_import_valid_csv(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->csvFile($this->validCsv()),
        ]);

        $response->assertOk();
        $response->assertJsonPath('inserted', 2);
        $response->assertJsonPath('failed', 0);
        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Budi',
            'nama_dengan_gelar' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'nip' => '198001012006041001',
            'jenis_pegawai_id' => RefJenisPegawai::where('nama', 'PNS')->firstOrFail()->id,
        ]);
        $this->assertSame('1985-02-12', Employee::where('nama_lengkap', 'Siti')->firstOrFail()->tanggal_lahir->format('Y-m-d'));
    }

    public function test_pegawai_cannot_import_employees(): void
    {
        $user = User::factory()->pegawai()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->csvFile($this->validCsv()),
        ]);

        $response->assertForbidden();
    }

    public function test_import_rejects_missing_header(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $csv = "Nama Pegawai,Email Pegawai\nBudi,budi@example.com\n";

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
    }

    public function test_import_rejects_unknown_jenis_pegawai_without_creating_rows(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $csv = str_replace(',PNS,1980-01-01', ',HONORER,1980-01-01', $this->validCsv());

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('failed', 1);
        $response->assertJsonPath('errors.0.row', 2);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_import_rejects_row_errors_without_creating_any_rows(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nip' => '198001012006041001']);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->csvFile($this->validCsv()),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('failed', 1);
        $response->assertJsonPath('errors.0.row', 2);
        $this->assertDatabaseMissing('employees', ['nama_lengkap' => 'Siti', 'nama_dengan_gelar' => 'Siti Aminah']);
    }

    public function test_import_rejects_duplicate_rows_without_creating_any_rows(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $csv = $this->validCsv().implode(',', [
            'Siti Duplikat',
            'siti@example.com',
            'III/c',
            'Analis Kepegawaian',
            '8',
            '198602122010042003',
            '081200000000',
            'Penata',
            'S2',
            '2044-02-12',
            'Siti',
            'Siti',
            'Teknik Informatika',
            'PNS',
            '1986-02-12',
        ])."\n";

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('failed', 1);
        $response->assertJsonPath('errors.0.row', 4);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_import_accepts_xlsx_file_on_legacy_endpoint(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->xlsxFile($this->validRows()),
        ]);

        $response->assertOk();
        $response->assertJsonPath('inserted', 2);
        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Budi',
            'nama_dengan_gelar' => 'Budi Santoso',
            'nip' => '198001012006041001',
        ]);
    }

    public function test_import_wizard_upload_preview_validate_and_execute_xlsx(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($this->validRows(includeNoColumn: true)),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');
        $upload->assertJsonPath('total_rows', 2);
        $upload->assertJsonMissing(['headers' => ['No']]);

        $preview = $this->getJson("/api/pegawai/import/{$batchId}/preview");
        $preview->assertOk();
        $preview->assertJsonPath('rows.0.data.NIP', '198001012006041001');

        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);
        $validation->assertOk();
        $validation->assertJsonPath('valid_count', 2);
        $validation->assertJsonPath('error_count', 0);

        $execute = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);
        $execute->assertOk();
        $execute->assertJsonPath('status', 'queued');

        $status = $this->getJson("/api/pegawai/import/{$batchId}/status");
        $status->assertOk();
        $status->assertJsonPath('status', 'completed');
        $status->assertJsonPath('result.inserted', 2);
        $status->assertJsonPath('result.failed', 0);

        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Siti',
            'nama_dengan_gelar' => 'Siti Aminah',
            'profil_status' => 'belum_lengkap',
            'status_aktif' => 'Aktif',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'IMPORT',
            'auditable_type' => 'Employee',
        ]);

        $importedEmployee = Employee::where('nip', '198502122010042002')->firstOrFail();
        $this->get(route('pegawai.show', $importedEmployee->id))
            ->assertOk()
            ->assertSeeText('Siti Aminah')
            ->assertSeeText('081298765432')
            ->assertSeeText('Pranata Komputer')
            ->assertSeeText('III/b')
            ->assertSeeText('Penata Muda Tingkat I')
            ->assertSeeText('8');
    }

    public function test_import_wizard_skips_nip_already_registered_in_database(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nip' => '198001012006041001']);

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($this->validRows()),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);

        $validation->assertOk();
        $validation->assertJsonPath('valid_count', 1);
        $validation->assertJsonPath('skip_count', 1);
        $validation->assertJsonPath('error_count', 0);
        $validation->assertJsonPath('results.0.status', 'skip');
        $validation->assertJsonPath('results.0.errors.NIP.0', 'NIP sudah terdaftar di database.');
        $validation->assertJsonPath('results.1.status', 'valid');
    }

    public function test_import_wizard_rejects_duplicate_nip_within_file_even_when_registered(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nip' => '198001012006041001']);
        $rows = $this->validRows();
        $rows[1][5] = '198001012006041001';

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($rows),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);

        $validation->assertOk();
        $validation->assertJsonPath('valid_count', 0);
        $validation->assertJsonPath('skip_count', 1);
        $validation->assertJsonPath('error_count', 1);
        $validation->assertJsonPath('results.0.status', 'skip');
        $validation->assertJsonPath('results.1.status', 'error');
        $validation->assertJsonPath('results.1.errors.NIP.0', 'NIP sudah ada pada baris 2.');
    }

    public function test_import_wizard_skips_nip_registered_after_validation(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile([$this->validRows()[0]]),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", [])
            ->assertOk()
            ->assertJsonPath('valid_count', 1);

        Employee::factory()->create(['nip' => '198001012006041001']);

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", [])
            ->assertOk()
            ->assertJsonPath('status', 'queued');

        $this->getJson("/api/pegawai/import/{$batchId}/status")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.inserted', 0)
            ->assertJsonPath('result.skipped', 1)
            ->assertJsonPath('result.failed', 0);
    }

    public function test_import_wizard_applies_saved_column_mapping_end_to_end(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        // Upload file Excel dengan header non-standar / kustom
        $customHeaders = [
            'Nama Pegawai Custom',
            'Person Custom',
            'Email Utama',
            'No NIP',
            'Status Pegawai Custom',
            'Telepon',
            'Tgl Lahir',
            'Jabatan Custom',
            'Golongan Custom',
            'Kelas Jabatan',
            'Pangkat',
            'Pendidikan',
            'Prodi',
            'Pensiun',
        ];

        $customRowValues = [
            'Ahmad Subandi, S.T.',
            'Ahmad Subandi',
            'ahmad.subandi@example.com',
            '199001012015031001',
            'PNS',
            '081234567890',
            '1990-01-01',
            'Analis Kepegawaian',
            'III/a',
            '7',
            'Penata Muda',
            'S1',
            'Teknik Informatika',
            '2048-01-01',
        ];

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFileWithHeaders($customHeaders, [$customRowValues], 'custom_headers.xlsx'),
            'type' => 'utama',
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');
        $this->assertContains('No NIP', $upload->json('headers'));
        // Header kustom tidak dikenali otomatis sehingga masuk daftar peringatan kolom ekstra.
        $this->assertContains('No NIP', $upload->json('warnings.unmatched_columns'));

        // Admin menyimpan pemetaan manual; mapping menjadi state batch yang dipakai validasi.
        $mapping = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => [
                'Nama Pegawai Custom' => 'Nama Pegawai',
                'Person Custom' => 'Person',
                'Email Utama' => 'Email Pegawai',
                'No NIP' => 'NIP',
                'Status Pegawai Custom' => 'Status Kepegawaian',
                'Telepon' => 'Nomor Telepon',
                'Tgl Lahir' => 'Tanggal Lahir',
                'Jabatan Custom' => 'Jabatan',
                'Golongan Custom' => 'Golongan',
                'Kelas Jabatan' => 'Kelas Jabatan',
                'Pangkat' => 'Pangkat',
                'Pendidikan' => 'Pendidikan Terakhir',
                'Prodi' => 'Prodi Pendidikan Terakhir',
                'Pensiun' => 'Pensiun',
            ],
        ]);

        $mapping->assertOk();
        $mapping->assertJsonPath('mapping.No NIP', 'NIP');

        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);

        $validation->assertOk();
        $validation->assertJsonPath('valid_count', 1);
        $validation->assertJsonPath('error_count', 0);

        $execute = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);
        $execute->assertOk();

        $status = $this->getJson("/api/pegawai/import/{$batchId}/status");
        $status->assertOk();
        $status->assertJsonPath('status', 'completed');
        $status->assertJsonPath('result.inserted', 1);

        $this->assertDatabaseHas('employees', [
            'nip' => '199001012015031001',
            'nama_lengkap' => 'Ahmad Subandi',
            'nama_dengan_gelar' => 'Ahmad Subandi, S.T.',
            'email_pribadi' => 'ahmad.subandi@example.com',
            'status_aktif' => 'Aktif',
        ]);
    }

    public function test_manual_mapping_imports_original_source_values_that_resemble_shifted_columns(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $headers = [
            'Nama Pegawai',
            'Person',
            'Email Pegawai',
            'NIP',
            'Status Kepegawaian',
            'NIK',
            'Tanggal Lahir',
            'Jabatan',
            'Golongan',
            'Kelas Jabatan',
            'Pangkat',
            'Nomor Telepon',
            'Prodi Pendidikan Terakhir',
            'Pensiun',
        ];
        $sourceRow = [
            'Rina Hartati, S.Kom.',
            'Rina Hartati',
            'rina.hartati@example.com',
            '199101012016032001',
            'PNS',
            '081298761234',
            '1991-01-01',
            'Pranata Komputer',
            'III/b',
            '8',
            'Penata Muda Tingkat I',
            'S1',
            'Teknik Informatika',
            '2049-01-01',
        ];

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFileWithHeaders($headers, [$sourceRow], 'manual_mapping.xlsx'),
            'type' => 'utama',
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $this->getJson("/api/pegawai/import/{$batchId}/preview")
            ->assertOk()
            ->assertJsonPath('rows.0.data.NIK', '081298761234')
            ->assertJsonPath('rows.0.data.Nomor Telepon', 'S1')
            ->assertJsonPath('rows.0.data.Tanggal Lahir', '1991-01-01');

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => [
                'NIK' => 'Nomor Telepon',
                'Nomor Telepon' => 'Pendidikan Terakhir',
            ],
        ])->assertOk();

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", [])
            ->assertOk()
            ->assertJsonPath('valid_count', 1)
            ->assertJsonPath('error_count', 0);

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", [])
            ->assertOk()
            ->assertJsonPath('status', 'queued');

        $this->assertDatabaseHas('employees', [
            'nip' => '199101012016032001',
            'no_hp' => '081298761234',
            'pendidikan_terakhir' => 'S1',
        ]);
        $this->assertSame(
            '1991-01-01',
            Employee::where('nip', '199101012016032001')->firstOrFail()->tanggal_lahir->format('Y-m-d'),
        );
    }

    public function test_upload_memperingatkan_kolom_role_sebagai_kolom_ekstra(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        // File dengan kolom Role warisan: Role tidak boleh menjadi target import dan harus
        // muncul sebagai peringatan kolom ekstra, bukan field yang dapat dipetakan.
        $headers = array_merge($this->headers(), ['Role']);
        $rows = array_map(fn (array $row): array => array_merge($row, ['pegawai']), $this->validRows());

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFileWithHeaders($headers, $rows, 'dengan_role.xlsx'),
        ]);

        $upload->assertOk();
        $upload->assertJsonPath('mapping.Role', 'tidak_dipakai');
        $this->assertContains('Role', $upload->json('warnings.unmatched_columns'));
        $this->assertNotContains('Role', $upload->json('required_targets'));
    }

    public function test_preview_membatasi_respons_sepuluh_baris_di_sisi_server(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $rows = [];
        foreach (range(1, 15) as $i) {
            $rows[] = [
                "Pegawai Nomor {$i}",
                "pegawai{$i}@example.com",
                'III/a',
                'Analis Kepegawaian',
                '7',
                sprintf('%018d', $i),
                '081234567890',
                'Penata Muda',
                'S1',
                '2038-01-01',
                "Pegawai {$i}",
                "Pegawai {$i}",
                'Manajemen',
                'PNS',
                '1990-01-01',
            ];
        }

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFileWithHeaders($this->headers(), $rows, 'lima_belas_baris.xlsx'),
        ]);

        $upload->assertOk();
        $upload->assertJsonPath('total_rows', 15);
        $batchId = $upload->json('batch_id');

        $preview = $this->getJson("/api/pegawai/import/{$batchId}/preview");
        $preview->assertOk();
        $preview->assertJsonPath('total_rows', 15);
        $this->assertCount(10, $preview->json('rows'));
    }

    public function test_preview_dapat_memuat_satu_baris_di_luar_batas_awal_untuk_diperbaiki(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $rows = [];
        foreach (range(1, 15) as $i) {
            $rows[] = [
                "Pegawai Nomor {$i}",
                "pegawai{$i}@example.com",
                'III/a',
                'Analis Kepegawaian',
                '7',
                sprintf('%018d', $i),
                '081234567890',
                'Penata Muda',
                'S1',
                '2038-01-01',
                "Pegawai {$i}",
                "Pegawai {$i}",
                'Manajemen',
                'PNS',
                '1990-01-01',
            ];
        }

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFileWithHeaders($this->headers(), $rows, 'baris_di_luar_preview.xlsx'),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $preview = $this->getJson("/api/pegawai/import/{$batchId}/preview?row=12");

        $preview->assertOk();
        $this->assertCount(1, $preview->json('rows'));
        $preview->assertJsonPath('rows.0.row', 12);
        $preview->assertJsonPath('rows.0.data.Nama Pegawai', 'Pegawai Nomor 11');

        $this->getJson("/api/pegawai/import/{$batchId}/preview?row=999")
            ->assertNotFound();
    }

    public function test_validasi_menolak_field_wajib_yang_belum_terpetakan_dengan_nama_field(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        // File tanpa kolom NIP: auto-mapping tidak dapat menemukan target wajib NIP.
        $headers = $this->headers();
        $nipIndex = array_search('NIP', $headers, true);
        unset($headers[$nipIndex]);

        $rows = array_map(function (array $row) use ($nipIndex): array {
            unset($row[$nipIndex]);

            return array_values($row);
        }, $this->validRows());

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFileWithHeaders(array_values($headers), $rows, 'tanpa_nip.xlsx'),
        ]);

        $upload->assertOk();
        $this->assertContains('NIP', $upload->json('warnings.missing_required'));

        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$upload->json('batch_id')}/validate", []);

        $validation->assertUnprocessable();
        $validation->assertJsonValidationErrors('mapping');
        $this->assertStringContainsString('NIP', (string) $validation->json('errors.mapping.0'));
    }

    public function test_mapping_endpoint_menyimpan_pemetaan_manual_parsial(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($this->validRows()),
        ]);
        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        // Menandai kolom opsional sebagai tidak dipakai: tersimpan tanpa missing required.
        $response = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => ['Pangkat' => 'tidak_dipakai'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('mapping.Pangkat', 'tidak_dipakai');
        // Header lain mempertahankan mapping otomatisnya (penyimpanan parsial aman).
        $response->assertJsonPath('mapping.NIP', 'NIP');
        $this->assertSame([], $response->json('warnings.missing_required'));
    }

    public function test_mapping_endpoint_menolak_target_ganda(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($this->validRows()),
        ]);
        $batchId = $upload->json('batch_id');

        // 'Nama Pegawai' sudah terpetakan otomatis dari kolomnya — target ganda ditolak.
        $response = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => ['Person' => 'Nama Pegawai'],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('mapping');
        $this->assertStringContainsString('Nama Pegawai', (string) $response->json('errors.mapping.0'));
    }

    public function test_mapping_endpoint_menolak_kolom_sumber_di_luar_batch(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($this->validRows()),
        ]);
        $batchId = $upload->json('batch_id');

        $response = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => ['Kolom Hantu' => 'NIP'],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('mapping');
    }

    public function test_mapping_endpoint_menolak_target_yang_tidak_dikenal(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($this->validRows()),
        ]);
        $batchId = $upload->json('batch_id');

        $response = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => ['NIP' => 'Field Hantu'],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('mapping.NIP');
    }

    public function test_mapping_endpoint_menolak_batch_milik_pengguna_lain(): void
    {
        $owner = User::factory()->adminKepegawaian()->create();
        $other = User::factory()->adminKepegawaian()->create();

        $this->actingAs($owner);
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($this->validRows()),
        ]);
        $batchId = $upload->json('batch_id');

        $this->actingAs($other);
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => ['Pangkat' => 'tidak_dipakai'],
        ])->assertForbidden();
    }

    public function test_import_wizard_persists_data_utama_snapshots_without_histories_or_tmt_calculation(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile([$this->validRows()[0]]),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);
        $validation->assertOk();
        $validation->assertJsonPath('valid_count', 1);
        $validation->assertJsonPath('error_count', 0);

        $this->mock(TmtCalculatorService::class)
            ->shouldNotReceive('syncForEmployee');

        $execute = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);
        $execute->assertOk();
        $execute->assertJsonPath('status', 'queued');

        $status = $this->getJson("/api/pegawai/import/{$batchId}/status");
        $status->assertOk();
        $status->assertJsonPath('status', 'completed');
        $status->assertJsonPath('result.inserted', 1);
        $status->assertJsonPath('result.failed', 0);

        $employee = Employee::where('nip', '198001012006041001')->firstOrFail();

        $this->assertSame('III/a', $employee->golongan_terakhir);
        $this->assertSame('Penata Muda', $employee->pangkat_terakhir);
        $this->assertSame('Analis Kepegawaian', $employee->jabatan_terakhir);
        $this->assertSame('7', $employee->kelas_jabatan_terakhir);
        $this->assertSame('S1', $employee->pendidikan_terakhir);
        $this->assertSame('Manajemen', $employee->prodi_pendidikan_terakhir);
        $this->assertSame('2038-01-01', $employee->tanggal_pensiun?->format('Y-m-d'));
        $this->assertSame(0, RankHistory::where('employee_id', $employee->id)->count());
        $this->assertSame(0, PositionHistory::where('employee_id', $employee->id)->count());
        $this->assertSame(0, SalaryHistory::where('employee_id', $employee->id)->count());
    }

    public function test_import_wizard_realigns_old_template_rows_without_nik_and_no_kk_values(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->csvFile($this->legacyShiftedCsv()),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $preview = $this->getJson("/api/pegawai/import/{$batchId}/preview");
        $preview->assertOk();
        // Preview mempertahankan nilai sumber; heuristik legacy baru diterapkan saat
        // validasi dengan mapping otomatis, bukan saat batch raw dibentuk.
        $preview->assertJsonPath('rows.0.data.NIK', '081234567890');
        $preview->assertJsonPath('rows.0.data.No KK', 'Penata Muda');
        $preview->assertJsonPath('rows.0.data.Nomor Telepon', 'S1');
        $preview->assertJsonPath('rows.0.data.Status Kepegawaian', 'pegawai');
        $preview->assertJsonPath('rows.0.data.Tanggal Lahir', null);

        // Browser selalu menyimpan mapping yang sedang tampil sebelum validasi.
        // Mapping otomatis yang tidak diubah tidak boleh dianggap sebagai mapping manual.
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => $upload->json('mapping'),
        ])->assertOk();

        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);
        $validation->assertOk();
        $validation->assertJsonPath('valid_count', 1);
        $validation->assertJsonPath('error_count', 0);

        $execute = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);
        $execute->assertOk();
        $execute->assertJsonPath('status', 'queued');

        $status = $this->getJson("/api/pegawai/import/{$batchId}/status");
        $status->assertOk();
        $status->assertJsonPath('status', 'completed');
        $status->assertJsonPath('result.inserted', 1);

        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Budi',
            'nama_dengan_gelar' => 'Budi Santoso',
            'nip' => '198001012006041001',
            'nik' => null,
            'no_kk' => null,
            'no_hp' => '081234567890',
            'pangkat_terakhir' => 'Penata Muda',
            'pendidikan_terakhir' => 'S1',
            'prodi_pendidikan_terakhir' => 'Manajemen',
        ]);
    }

    public function test_import_requires_documented_required_excel_fields(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $csv = str_replace('budi@example.com', '', $this->validCsv());

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('errors.0.row', 2);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_import_rejects_row_without_nip(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $csv = str_replace(',198001012006041001,', ',,', $this->validCsv());

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('errors.0.row', 2);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_import_rejects_row_without_status_kepegawaian(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $csv = str_replace(',PNS,1980-01-01', ',,1980-01-01', $this->validCsv());

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('errors.0.row', 2);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_old_employee_import_endpoint_is_not_available(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf('/api/v1/employees/import', [
            'file' => $this->csvFile($this->validCsv()),
        ]);

        $response->assertNotFound();
    }

    public function test_perubahan_mapping_menginvalidasi_hasil_validasi_lama(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($this->validRows()),
        ]);
        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        // Validasi awal dengan mapping otomatis
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", [])
            ->assertOk()
            ->assertJsonPath('valid_count', 2);

        // Ubah mapping: hasil validasi lama harus kedaluwarsa
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => ['Pangkat' => 'tidak_dipakai'],
        ])->assertOk();

        // Eksekusi wajib menolak karena validasi tidak lagi mewakili mapping aktif
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", [])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Data belum divalidasi. Jalankan validasi terlebih dahulu.']);
    }

    public function test_mapping_identik_tidak_menginvalidasi_validasi(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($this->validRows()),
        ]);
        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", [])
            ->assertOk();

        // Simpan mapping dengan nilai yang persis sama dengan auto-map (tidak berubah)
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => ['Pangkat' => 'Pangkat'],
        ])->assertOk();

        // Validasi tidak kedaluwarsa — eksekusi tetap berjalan
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", [])
            ->assertOk()
            ->assertJsonPath('status', 'queued');
    }

    private function validCsv(): string
    {
        return implode(',', $this->headers())."\n".
        implode(',', $this->validRows()[0])."\n".
        implode(',', $this->validRows()[1])."\n";
    }

    private function legacyShiftedCsv(): string
    {
        return implode(',', [
            'No',
            'Nama Pegawai',
            'Email Pegawai',
            'Golongan',
            'Jabatan',
            'Kelas Jabatan',
            'NIP',
            'NIK',
            'No KK',
            'Nomor Telepon',
            'Pangkat',
            'Pendidikan Terakhir',
            'Pensiun',
            'Person',
            'Person Formula',
            'Prodi Pendidikan Terakhir',
            'Status Kepegawaian',
            'Tanggal Lahir',
            'Role',
        ])."\n".
        implode(',', [
            '1',
            'Budi Santoso',
            'budi@example.com',
            'III/a',
            'Analis Kepegawaian',
            '7',
            '198001012006041001',
            '081234567890',
            'Penata Muda',
            'S1',
            '2038-01-01',
            'Budi',
            'Budi',
            'Manajemen',
            'PNS',
            '1980-01-01',
            'pegawai',
        ])."\n";
    }

    private function headers(bool $includeNoColumn = false): array
    {
        $headers = [
            'Nama Pegawai',
            'Email Pegawai',
            'Golongan',
            'Jabatan',
            'Kelas Jabatan',
            'NIP',
            'Nomor Telepon',
            'Pangkat',
            'Pendidikan Terakhir',
            'Pensiun',
            'Person',
            'Person Formula',
            'Prodi Pendidikan Terakhir',
            'Status Kepegawaian',
            'Tanggal Lahir',
        ];

        return $includeNoColumn ? array_merge(['No'], $headers) : $headers;
    }

    private function validRows(bool $includeNoColumn = false): array
    {
        $rows = [
            [
                'Budi Santoso',
                'budi@example.com',
                'III/a',
                'Analis Kepegawaian',
                '7',
                '198001012006041001',
                '081234567890',
                'Penata Muda',
                'S1',
                '2038-01-01',
                'Budi',
                'Budi',
                'Manajemen',
                'PNS',
                '1980-01-01',
            ],
            [
                'Siti Aminah',
                'siti@example.com',
                'III/b',
                'Pranata Komputer',
                '8',
                '198502122010042002',
                '081298765432',
                'Penata Muda Tingkat I',
                'S2',
                '2043-02-12',
                'Siti',
                'Siti',
                'Teknik Informatika',
                'PNS',
                '12/02/1985',
            ],
        ];

        if (! $includeNoColumn) {
            return $rows;
        }

        return array_map(
            fn (array $row, int $index) => array_merge([$index + 1], $row),
            $rows,
            array_keys($rows),
        );
    }

    private function csvFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'employees');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'employees.csv', 'text/csv', null, true);
    }

    private function xlsxFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $includeNoColumn = count($rows[0] ?? []) === count($this->headers()) + 1;
        $sheet->fromArray($this->headers($includeNoColumn), null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'employees').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'employees.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    private function xlsxFileWithHeaders(array $headers, array $rows, string $filename): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'employees').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
