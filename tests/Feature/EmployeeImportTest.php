<?php

namespace Tests\Feature;

use App\Actions\Employees\UploadImportBatchAction;
use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\SalaryHistory;
use App\Models\User;
use App\Services\Employees\TmtCalculatorService;
use App\Support\EmployeeImport\ImportColumnMapping;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $budi = Employee::where('nama_lengkap', 'Budi')->firstOrFail();
        $siti = Employee::where('nama_lengkap', 'Siti')->firstOrFail();
        $this->assertSame('1985-02-12', $siti->tanggal_lahir->format('Y-m-d'));

        foreach ([$budi, $siti] as $employee) {
            $this->assertSame(1, $employee->milestones()->count());
            $milestone = $employee->milestones()->sole();
            $this->assertSame(EmployeeMilestone::TYPE_PENSIUN, $milestone->type);
            $this->assertSame('employee_import', $milestone->metadata['source']);
            $this->assertTrue($milestone->metadata['is_manual']);
        }

        $this->artisan('milestone:backfill', [
            '--recalculate-legacy-pension' => true,
            '--no-interaction' => true,
        ])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        $this->assertSame('2038-01-01', $budi->fresh()->tanggal_pensiun?->toDateString());
        $this->assertSame('2043-02-12', $siti->fresh()->tanggal_pensiun?->toDateString());
        $this->assertSame(1, $budi->milestones()->count());
        $this->assertSame(1, $siti->milestones()->count());
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
        $this->assertDatabaseCount('employees', 1); // Termasuk Employee milik aktor autentikasi.
    }

    public function test_import_skips_existing_nip_and_inserts_other_valid_rows(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nip' => '198001012006041001']);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->csvFile($this->validCsv()),
        ]);

        $response->assertOk();
        $response->assertJsonPath('inserted', 1);
        $response->assertJsonPath('skipped', 1);
        $response->assertJsonPath('failed', 0);
        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Siti',
            'nama_dengan_gelar' => 'Siti Aminah',
        ]);
    }

    public function test_wizard_prioritizes_existing_email_error_over_existing_nip_skip(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create([
            'nip' => '198001012006041001',
            'email_pribadi' => 'budi@example.com',
        ]);

        $this->actingAs($user);
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile([$this->validRows()[0]]),
        ])->assertOk();

        $this->postJsonWithCsrf("/api/pegawai/import/{$upload->json('batch_id')}/validate", [])
            ->assertOk()
            ->assertJsonPath('valid_count', 0)
            ->assertJsonPath('error_count', 1)
            ->assertJsonPath('skip_count', 0)
            ->assertJsonPath('results.0.status', 'error')
            ->assertJsonPath('results.0.errors.Email Pegawai.0', 'Email pegawai sudah terdaftar di database.');
    }

    /** Email pegawai nonaktif tetap dicadangkan untuk identitas pegawai tersebut. */
    public function test_wizard_rejects_email_owned_by_soft_deleted_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $inactiveEmployee = Employee::factory()->create([
            'nip' => '199901010000000001',
            'email_pribadi' => 'budi@example.com',
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => RefStatusPegawai::query()->where('kode', 'NONAKTIF')->value('id'),
        ]);

        $this->actingAs($user);
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile([$this->validRows()[0]]),
        ])->assertOk();

        $this->postJsonWithCsrf("/api/pegawai/import/{$upload->json('batch_id')}/validate", [])
            ->assertOk()
            ->assertJsonPath('valid_count', 0)
            ->assertJsonPath('error_count', 1)
            ->assertJsonPath('skip_count', 0)
            ->assertJsonPath('results.0.status', 'error')
            ->assertJsonPath('results.0.errors.Email Pegawai.0', 'Email pegawai sudah terdaftar di database.');
    }

    /** NIP pegawai nonaktif tetap dikenali sebagai data lama yang harus dilewati. */
    public function test_wizard_skips_nip_owned_by_soft_deleted_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $inactiveEmployee = Employee::factory()->create([
            'nip' => '198001012006041001',
            'email_pribadi' => 'arsip@example.com',
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => RefStatusPegawai::query()->where('kode', 'NONAKTIF')->value('id'),
        ]);

        $this->actingAs($user);
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile([$this->validRows()[0]]),
        ])->assertOk();

        $this->postJsonWithCsrf("/api/pegawai/import/{$upload->json('batch_id')}/validate", [])
            ->assertOk()
            ->assertJsonPath('valid_count', 0)
            ->assertJsonPath('error_count', 0)
            ->assertJsonPath('skip_count', 1)
            ->assertJsonPath('results.0.status', 'skip')
            ->assertJsonPath('results.0.errors.NIP.0', 'NIP sudah terdaftar di database.');
    }

    public function test_wizard_prioritizes_duplicate_nip_in_file_over_database_skip(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nip' => '198001012006041001']);
        $rows = $this->validRows();
        $rows[1][5] = $rows[0][5];

        $this->actingAs($user);
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($rows),
        ])->assertOk();

        $this->postJsonWithCsrf("/api/pegawai/import/{$upload->json('batch_id')}/validate", [])
            ->assertOk()
            ->assertJsonPath('valid_count', 0)
            // Kedua baris error: baris 1 di-upgrade secara retroactive (tadinya skip DB karena NIP
            // sudah ada di database, tapi baris 2 menduplikasi NIP yang sama dalam file sehingga
            // retroactive upgrade mengubah baris 1 menjadi error pula — lihat logika $duplicatedNips).
            ->assertJsonPath('error_count', 2)
            ->assertJsonPath('skip_count', 0)
            ->assertJsonPath('results.0.status', 'error')
            // Pesan skip database sudah tercatat sebelum validasi baris berikutnya
            // menemukan duplikasi; upgrade retroaktif menambahkan pesan duplikasi sesudahnya.
            ->assertJsonPath('results.0.errors.NIP.0', 'NIP sudah terdaftar di database.')
            ->assertJsonPath('results.0.errors.NIP.1', 'NIP sudah ada pada baris 3.')
            ->assertJsonPath('results.1.status', 'error')
            ->assertJsonPath('results.1.errors.NIP.0', 'NIP sudah ada pada baris 2.');

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
        $this->assertDatabaseCount('employees', 1); // Termasuk Employee milik aktor autentikasi.
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

    /**
     * US-3.2 AC-5: Remapping kolom Role ke field SIMPEG manapun via endpoint harus
     * dinormalisasi paksa menjadi tidak_dipakai — bukan sekedar tidak tersedia di auto-map.
     *
     * Skenario ini membuktikan bahwa request langsung Role → Pangkat ditolak/dinormalisasi
     * bahkan ketika client mengirim JSON secara manual, tanpa melalui UI.
     */
    public function test_save_mapping_normalizes_role_source_ke_tidak_dipakai(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        // Upload file dengan kolom Role.
        $headers = array_merge($this->headers(), ['Role']);
        $rows = array_map(fn (array $row): array => array_merge($row, ['pegawai']), $this->validRows());

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFileWithHeaders($headers, $rows, 'dengan_role.xlsx'),
        ])->assertOk();

        $batchId = $upload->json('batch_id');

        // Admin mencoba memetakan Role → Pangkat secara langsung melalui endpoint.
        // Backend harus menormalisasi Role menjadi tidak_dipakai tanpa mengembalikan error,
        // karena normalisasi adalah pilihan yang lebih UX-friendly dari penolakan keras.
        $mapping = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => [
                'Pangkat' => 'tidak_dipakai',
                'Role' => 'Pangkat', // <-- skenario exploit yang harus dinormalisasi
            ],
        ]);

        $mapping->assertOk();

        // Role harus dipaksa kembali ke tidak_dipakai — bukan Pangkat.
        $mapping->assertJsonPath('mapping.Role', 'tidak_dipakai');

        // Pangkat masih tidak_dipakai sebagaimana dikirim admin (duplikasi tidak terjadi).
        $mapping->assertJsonPath('mapping.Pangkat', 'tidak_dipakai');
    }

    /**
     * US-3.2 AC-5: Fail-closed defense di ImportColumnMapping::apply() — nilai kolom Role
     * tidak boleh lolos ke field SIMPEG manapun meski mapping cache dimanipulasi.
     *
     * Membuktikan bahwa lapisan kedua (apply) independen dari SaveImportMappingAction.
     */
    public function test_mapping_endpoint_normalizes_role_header_variations(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $this->actingAs($user);

        foreach (['Role', 'ROLE', 'role', '  rOlE  '] as $sourceHeader) {
            $headers = array_merge($this->headers(), [$sourceHeader]);
            $rows = array_map(fn (array $row): array => array_merge($row, ['pegawai']), $this->validRows());
            $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
                'file' => $this->xlsxFileWithHeaders($headers, $rows, 'role_variants.xlsx'),
            ])->assertOk();

            $storedHeader = collect(array_keys($upload->json('mapping')))
                ->first(fn (string $header): bool => mb_strtolower(trim($header)) === 'role');

            $this->assertNotNull($storedHeader);
            $this->postJsonWithCsrf("/api/pegawai/import/{$upload->json('batch_id')}/mapping", [
                'mapping' => ['Pangkat' => 'tidak_dipakai', $storedHeader => 'Pangkat'],
            ])
                ->assertOk()
                ->assertJsonPath("mapping.{$storedHeader}", 'tidak_dipakai');
        }
    }

    public function test_apply_fail_closed_mengabaikan_nilai_kolom_role(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        // Upload file dengan kolom Role.
        $headers = array_merge($this->headers(), ['Role']);
        $rows = array_map(fn (array $row): array => array_merge($row, ['pegawai']), $this->validRows());

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFileWithHeaders($headers, $rows, 'role_apply_test.xlsx'),
        ])->assertOk();

        $batchId = $upload->json('batch_id');

        // Simpan mapping lewat endpoint — normalisasi sudah berjalan di sini.
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => [
                'Role' => 'Pangkat', // akan dinormalisasi menjadi tidak_dipakai
            ],
        ])->assertOk();

        // Jalankan validasi dan eksekusi — nilai kolom Role ('pegawai') tidak boleh
        // tersimpan ke field apapun pada pegawai yang diimpor.
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", [])
            ->assertOk()
            ->assertJsonPath('valid_count', 2);

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", [])
            ->assertOk()
            ->assertJsonPath('status', 'queued');

        // Semua pegawai berhasil diimpor tanpa data Role masuk ke kolom manapun.
        $this->assertDatabaseHas('employees', ['nip' => '198001012006041001']);
        $this->assertDatabaseHas('employees', ['nip' => '198502122010042002']);

        // Field pangkat_terakhir tidak boleh berisi nilai 'pegawai' (nilai kolom Role).
        $this->assertDatabaseMissing('employees', ['pangkat_terakhir' => 'pegawai']);
    }

    public function test_partial_mapping_save_cleans_legacy_role_mapping(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $this->actingAs($user);

        $headers = array_merge($this->headers(), ['Role']);
        $rows = array_map(fn (array $row): array => array_merge($row, ['pegawai']), $this->validRows());
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFileWithHeaders($headers, $rows, 'legacy_role_mapping.xlsx'),
        ])->assertOk();

        $batchId = $upload->json('batch_id');
        $cacheKey = UploadImportBatchAction::CACHE_PREFIX.$batchId;
        $batch = Cache::get($cacheKey);
        $batch['mapping']['Role'] = 'Pangkat';
        Cache::put($cacheKey, $batch, now()->addMinutes(30));

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => ['Pangkat' => 'tidak_dipakai'],
        ])
            ->assertOk()
            ->assertJsonPath('mapping.Role', 'tidak_dipakai');
    }

    public function test_apply_ignores_role_header_variations(): void
    {
        foreach (['Role', 'ROLE', 'role', '  rOlE  '] as $sourceHeader) {
            $mapped = ImportColumnMapping::apply(
                [$sourceHeader => 'pegawai', 'Pangkat' => 'Penata Muda'],
                [$sourceHeader => 'Pangkat', 'Pangkat' => 'Pangkat'],
            );

            $this->assertSame(['Pangkat' => 'Penata Muda'], $mapped);
        }
    }

    #[DataProvider('reservedRoleHeaderProvider')]
    public function test_mapping_endpoint_menormalisasi_source_role_setelah_normalisasi(string $sourceHeader): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $headers = array_merge($this->headers(), [$sourceHeader]);
        $rows = array_map(fn (array $row): array => array_merge($row, ['admin_kepegawaian']), $this->validRows());
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFileWithHeaders($headers, $rows, 'source_role_reserved.xlsx'),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $storedHeader = collect(array_keys($upload->json('mapping')))
            ->first(fn (string $header): bool => mb_strtolower(preg_replace('/\s+/', ' ', trim($header)) ?? $header) === 'role');

        $this->assertNotNull($storedHeader);

        // Target asli dilepas lebih dulu agar normalisasi reserved source diuji
        // secara independen dari validasi target ganda.
        $response = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/mapping", [
            'mapping' => [
                'Pangkat' => 'tidak_dipakai',
                $storedHeader => 'Pangkat',
            ],
        ]);

        $response->assertOk();
        $this->assertSame('tidak_dipakai', $response->json("mapping.{$storedHeader}"));
    }

    #[DataProvider('reservedRoleHeaderProvider')]
    public function test_apply_mapping_mengabaikan_source_role_sebagai_pertahanan_domain(string $sourceHeader): void
    {
        $mapped = ImportColumnMapping::apply(
            [
                $sourceHeader => 'admin_kepegawaian',
                'Pangkat' => 'Penata Muda',
            ],
            [
                $sourceHeader => 'NIP',
                'Pangkat' => 'Pangkat',
            ],
        );

        $this->assertSame([
            'Pangkat' => 'Penata Muda',
        ], $mapped, "Source reserved {$sourceHeader} tidak boleh diterapkan ke target import.");
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

    public function test_validate_endpoint_rejects_malformed_edited_rows_with_422(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $this->actingAs($user);
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($this->validRows()),
        ])->assertOk();

        $this->postJsonWithCsrf("/api/pegawai/import/{$upload->json('batch_id')}/validate", [
            'rows' => [
                ['row' => 1, 'data' => 'bukan-array'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rows.0.row', 'rows.0.data']);
    }

    public function test_import_batch_mutations_preserve_role_authorization_boundary(): void
    {
        $owner = User::factory()->adminKepegawaian()->create();
        $unauthorizedUser = User::factory()->pegawai()->create();
        $this->actingAs($owner);
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($this->validRows()),
        ])->assertOk();
        $batchId = $upload->json('batch_id');

        $this->actingAs($unauthorizedUser);
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", [])->assertForbidden();
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", [])->assertForbidden();
    }

    public function test_import_batch_mutations_reject_malformed_uuid_at_route_boundary(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $this->actingAs($user);

        $this->postJsonWithCsrf('/api/pegawai/import/bukan-uuid/validate', [])->assertNotFound();
        $this->postJsonWithCsrf('/api/pegawai/import/bukan-uuid/mapping', [
            'mapping' => ['NIP' => 'NIP'],
        ])->assertNotFound();
        $this->postJsonWithCsrf('/api/pegawai/import/bukan-uuid/execute', [])->assertNotFound();
    }

    public function test_import_wizard_persists_data_utama_snapshots_without_histories_or_tmt_calculation(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $row = $this->validRows()[0];
        $row[12] = 'Program Studi Import Tanpa Referensi';

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile([$row]),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);
        $validation->assertOk();
        $validation->assertJsonPath('valid_count', 1);
        $validation->assertJsonPath('error_count', 0);

        $calculator = new class extends TmtCalculatorService
        {
            public int $recordImportedPensionDateCalls = 0;

            public int $syncForEmployeeCalls = 0;

            public function syncForEmployee(
                Employee $employee,
                ?bool $pensionDateIsAuthoritative = null,
                bool $recalculateLegacyPension = false,
            ): void {
                $this->syncForEmployeeCalls++;
            }

            public function recordImportedPensionDate(Employee $employee): void
            {
                $this->recordImportedPensionDateCalls++;
                parent::recordImportedPensionDate($employee);
            }
        };
        $this->app->instance(TmtCalculatorService::class, $calculator);

        $execute = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);
        $execute->assertOk();
        $execute->assertJsonPath('status', 'queued');

        $status = $this->getJson("/api/pegawai/import/{$batchId}/status");
        $status->assertOk();
        $status->assertJsonPath('status', 'completed');
        $status->assertJsonPath('result.inserted', 1);
        $status->assertJsonPath('result.failed', 0);
        $this->assertSame(0, $calculator->syncForEmployeeCalls);
        $this->assertSame(1, $calculator->recordImportedPensionDateCalls);

        $employee = Employee::where('nip', '198001012006041001')->firstOrFail();

        $this->assertSame('III/a', $employee->golongan_terakhir);
        $this->assertSame('Penata Muda', $employee->pangkat_terakhir);
        $this->assertSame('Analis Kepegawaian', $employee->jabatan_terakhir);
        $this->assertSame('7', $employee->kelas_jabatan_terakhir);
        $this->assertSame('S1', $employee->pendidikan_terakhir);
        $this->assertSame('Program Studi Import Tanpa Referensi', $employee->prodi_pendidikan_terakhir);
        $this->assertNull($employee->program_studi_id);
        $this->assertDatabaseMissing('ref_program_studi', [
            'nama' => 'Program Studi Import Tanpa Referensi',
        ]);
        $this->assertSame('2038-01-01', $employee->tanggal_pensiun?->format('Y-m-d'));
        $this->assertSame(0, RankHistory::where('employee_id', $employee->id)->count());
        $this->assertSame(0, PositionHistory::where('employee_id', $employee->id)->count());
        $this->assertSame(0, SalaryHistory::where('employee_id', $employee->id)->count());

        $this->assertSame(1, $employee->milestones()->count());
        $pensionMilestone = $employee->milestones()->sole();
        $this->assertSame(EmployeeMilestone::TYPE_PENSIUN, $pensionMilestone->type);
        $this->assertSame('employee_import', $pensionMilestone->metadata['source']);
        $this->assertTrue($pensionMilestone->metadata['is_manual']);

        // Import telah selesai dan ekspektasi mock melindungi batas import saja;
        // backfill berikutnya memang harus memakai kalkulator nyata.
        $this->app->forgetInstance(TmtCalculatorService::class);

        $this->artisan('milestone:backfill', [
            '--recalculate-legacy-pension' => true,
            '--no-interaction' => true,
        ])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        $employee->refresh();
        $pensionMilestone->refresh();
        $this->assertSame('2038-01-01', $employee->tanggal_pensiun?->toDateString());
        $this->assertSame('2038-01-01', $pensionMilestone->milestone_date->toDateString());
        $this->assertNotSame('legacy_unverified', $pensionMilestone->metadata['source']);
        $this->assertSame(1, $employee->milestones()->count());
    }

    /** Import tanpa tanggal pensiun tidak boleh memanggil kalkulator atau membuat milestone. */
    public function test_import_without_pension_date_does_not_trigger_tmt_calculation(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $row = $this->validRows()[0];
        $row[9] = null;

        $this->mock(TmtCalculatorService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('syncForEmployee');
            $mock->shouldNotReceive('recordImportedPensionDate');
        });

        $this->actingAs($user);
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile([$row]),
        ])->assertOk();
        $batchId = $upload->json('batch_id');

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", [])
            ->assertOk()
            ->assertJsonPath('valid_count', 1);
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", [])
            ->assertOk()
            ->assertJsonPath('status', 'queued');

        $employee = Employee::query()->where('nip', '198001012006041001')->firstOrFail();
        $this->assertNull($employee->tanggal_pensiun);
        $this->assertSame('III/a', $employee->golongan_terakhir);
        $this->assertSame('Analis Kepegawaian', $employee->jabatan_terakhir);
        $this->assertSame(0, $employee->milestones()->count());
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
        $this->assertDatabaseCount('employees', 1); // Termasuk Employee milik aktor autentikasi.
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
        $this->assertDatabaseCount('employees', 1); // Termasuk Employee milik aktor autentikasi.
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
        $this->assertDatabaseCount('employees', 1); // Termasuk Employee milik aktor autentikasi.
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

    public static function reservedRoleHeaderProvider(): array
    {
        return [
            'kanonis' => ['Role'],
            'spasi dan huruf besar' => ['  ROLE  '],
            'huruf campuran' => ['rOlE'],
        ];
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
