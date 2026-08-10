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

    /**
     * K-US-02: NIP existing in database should be skipped, not error.
     * When one row has NIP existing, it gets skipped but other valid rows are still inserted.
     */
    public function test_import_skips_nip_existing_and_inserts_other_valid_rows(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nip' => '198001012006041001']);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEE_IMPORT_ENDPOINT, [
            'file' => $this->csvFile($this->validCsv()),
        ]);

        // Should succeed with 1 inserted (Siti) and 1 skipped (Budi)
        $response->assertOk();
        $response->assertJsonPath('inserted', 1);
        $response->assertJsonPath('skipped', 1);
        $response->assertJsonPath('failed', 0);
        
        // Siti should be inserted
        $this->assertDatabaseHas('employees', ['nama_lengkap' => 'Siti', 'nama_dengan_gelar' => 'Siti Aminah']);
    }

    /**
     * K-US-02: NIP yang sudah ada di database → skip (bukan error)
     * Test wizard flow untuk memverifikasi bahwa duplicate NIP di-skip
     */
    public function test_import_wizard_skips_duplicate_nip_from_database(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        // Buat employee dengan NIP yang sama dengan row pertama
        Employee::factory()->create(['nip' => '198001012006041001']);

        $this->actingAs($user);

        // Upload file dengan 2 rows, row pertama duplikat NIP
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($this->validRows(includeNoColumn: true)),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');
        $upload->assertJsonPath('total_rows', 2);

        // Validasi: row pertama harus skip, row kedua valid
        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);
        $validation->assertOk();
        $validation->assertJsonPath('valid_count', 1);     // Hanya row 2 yang valid
        $validation->assertJsonPath('skip_count', 1);      // Row 1 di-skip
        $validation->assertJsonPath('error_count', 0);     // Tidak ada error
        $validation->assertJsonPath('results.0.status', 'skip');
        $validation->assertJsonPath('results.0.row', 2);   // Row 2 (index 0 + header)
        $validation->assertJsonPath('results.1.status', 'valid');
        $validation->assertJsonPath('results.1.row', 3);   // Row 3

        // Execute: hanya row yang valid yang diimpor
        $execute = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);
        $execute->assertOk();
        $execute->assertJsonPath('status', 'queued');

        $status = $this->getJson("/api/pegawai/import/{$batchId}/status");
        $status->assertOk();
        $status->assertJsonPath('status', 'completed');
        $status->assertJsonPath('result.inserted', 1);     // Hanya 1 row berhasil (Siti)
        $status->assertJsonPath('result.skipped', 1);      // 1 row di-skip (Budi)
        $status->assertJsonPath('result.failed', 0);

        // Verifikasi: hanya Siti yang di-insert, Budi tidak duplikat
        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Siti',
            'nip' => '198502122010042002',
        ]);
        $this->assertDatabaseCount('employees', 2); // 1 existing + 1 new
    }

    /**
     * K-US-02: NIP ganda dalam satu berkas → tetap error
     */
    public function test_import_wizard_rejects_duplicate_nip_within_file(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        // Row 1 dan Row 2 punya NIP yang sama
        $rows = [
            $this->validRows()[0],
            [
                'Budi Duplikat',
                'budi2@example.com',
                'III/a',
                'Analis',
                '7',
                '198001012006041001', // <- NIP sama dengan row 1
                '081200000000',
                'Penata Muda',
                'S1',
                '2038-01-01',
                'Budi',
                'Budi',
                'Manajemen',
                'PNS',
                '1980-01-01',
                'pegawai',
            ],
        ];

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile($rows),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');
        $upload->assertJsonPath('total_rows', 2);

        // Validasi: row kedua harus error (duplikat NIP dalam file)
        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);
        $validation->assertOk();
        $validation->assertJsonPath('valid_count', 1);     // Hanya row 1 valid
        $validation->assertJsonPath('error_count', 1);     // Row 2 error (duplikat dalam file)
        $validation->assertJsonPath('skip_count', 0);      // Tidak ada skip
        $validation->assertJsonPath('results.0.status', 'valid');
        $validation->assertJsonPath('results.1.status', 'error');

        // Execute boleh jalan sesuai AC-4 (Import Hanya yang Valid)
        // Hanya row yang valid yang akan diimpor
        $execute = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);
        $execute->assertOk();
        $execute->assertJsonPath('status', 'queued');

        $status = $this->getJson("/api/pegawai/import/{$batchId}/status");
        $status->assertOk();
        $status->assertJsonPath('status', 'completed');
        $status->assertJsonPath('result.inserted', 1);     // Hanya 1 row valid berhasil
        $status->assertJsonPath('result.failed', 1);       // 1 row error tidak diimpor
        $status->assertJsonPath('result.skipped', 0);

        // Verifikasi: hanya Budi yang di-insert (row 1 valid), Budi Duplikat tidak
        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Budi',
            'nip' => '198001012006041001',
        ]);
        $this->assertDatabaseMissing('employees', [
            'nama_lengkap' => 'Budi Duplikat',
        ]);
        $this->assertDatabaseCount('employees', 1); // Hanya 1 yang berhasil
    }

    /**
     * K-US-02: Email yang sudah terdaftar → tetap error (tidak di-skip)
     */
    public function test_import_wizard_rejects_duplicate_email(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        // Buat employee dengan email yang sama
        Employee::factory()->create(['email_pribadi' => 'budi@example.com']);

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFile([$this->validRows()[0]]),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        // Validasi: harus error (email duplikat), bukan skip
        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);
        $validation->assertOk();
        $validation->assertJsonPath('valid_count', 0);
        $validation->assertJsonPath('error_count', 1);     // Error karena email duplikat
        $validation->assertJsonPath('skip_count', 0);      // Tidak di-skip
        $validation->assertJsonPath('results.0.status', 'error');
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
            'pegawai',
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
        $preview->assertJsonPath('rows.0.data.NIK', null);
        $preview->assertJsonPath('rows.0.data.No KK', null);
        $preview->assertJsonPath('rows.0.data.Nomor Telepon', '081234567890');
        $preview->assertJsonPath('rows.0.data.Pangkat', 'Penata Muda');
        $preview->assertJsonPath('rows.0.data.Pendidikan Terakhir', 'S1');
        $preview->assertJsonPath('rows.0.data.Pensiun', '2038-01-01');
        $preview->assertJsonPath('rows.0.data.Prodi Pendidikan Terakhir', 'Manajemen');
        $preview->assertJsonPath('rows.0.data.Status Kepegawaian', 'PNS');
        $preview->assertJsonPath('rows.0.data.Tanggal Lahir', '1980-01-01');

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

    public function test_duplicate_nip_in_file_is_error_even_if_nip_exists_in_database(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        // NIP sudah ada di database
        Employee::factory()->create(['nip' => '198001012006041001']);

        // Buat file dengan 2 baris yang memiliki NIP yang sama (dan NIP tersebut sudah ada di DB)
        $csv = implode(',', $this->headers())."\n".
            implode(',', $this->validRows()[0])."\n". // Baris 2: NIP 198001012006041001
            implode(',', array_map(function ($value) {
                // Baris 3: NIP sama (198001012006041001) tapi nama berbeda
                if ($value === 'Budi Santoso') {
                    return 'Budi Duplikat';
                }
                if ($value === 'budi@example.com') {
                    return 'budi.duplikat@example.com';
                }

                return $value;
            }, $this->validRows()[0]))."\n";

        $this->actingAs($user);
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->csvFile($csv),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);
        $validation->assertOk();

        // Baris 2: skip karena NIP sudah ada di database (dan tidak ada duplicate in-file)
        // Baris 3: error karena NIP sudah ada pada baris 2 (duplicate in-file)
        $validation->assertJsonPath('valid_count', 0);
        $validation->assertJsonPath('error_count', 1); // Baris 3 harus error
        $validation->assertJsonPath('skip_count', 1); // Baris 2 boleh skip
        $validation->assertJsonPath('results.0.status', 'skip'); // Baris 2
        $validation->assertJsonPath('results.0.errors.NIP.0', 'NIP sudah terdaftar di database.');
        $validation->assertJsonPath('results.1.status', 'error'); // Baris 3
        $validation->assertJsonPath('results.1.errors.NIP.0', 'NIP sudah ada pada baris 2.');
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
            'Role',
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
                'pegawai',
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
                'pegawai',
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
