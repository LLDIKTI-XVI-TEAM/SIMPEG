<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\User;
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
            'nama_lengkap' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'nip' => '198001012006041001',
            'jenis_pegawai_id' => RefJenisPegawai::where('nama', 'PNS')->firstOrFail()->id,
        ]);
        $this->assertSame('1985-02-12', Employee::where('nama_lengkap', 'Siti Aminah')->firstOrFail()->tanggal_lahir->format('Y-m-d'));
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
        $this->assertDatabaseMissing('employees', ['nama_lengkap' => 'Siti Aminah']);
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
            'nama_lengkap' => 'Budi Santoso',
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
        $execute->assertJsonPath('inserted', 2);
        $execute->assertJsonPath('failed', 0);
        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Siti Aminah',
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
        $execute->assertJsonPath('inserted', 1);

        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Budi Santoso',
            'nip' => '198001012006041001',
            'nik' => null,
            'no_kk' => null,
            'no_hp' => '081234567890',
            'pangkat_terakhir' => 'Penata Muda',
            'pendidikan_terakhir' => 'S1',
            'prodi_pendidikan_terakhir' => 'Manajemen',
        ]);
    }

    public function test_import_wizard_executes_pelengkap_xlsx_template(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Budi Santoso',
            'nip' => '198001012006041001',
            'nik' => null,
            'no_kk' => null,
        ]);

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFileWithHeaders(
                ['NIP', 'NIK', 'No KK', 'Tempat Lahir', 'Jenis Kelamin', 'Agama', 'Status Kawin', 'Golongan Darah'],
                [[
                    $employee->nip,
                    '1234567890123456',
                    '6543210987654321',
                    'Manado',
                    'Laki-laki',
                    'Islam',
                    'Menikah',
                    'O',
                ]],
                'template_pelengkap.xlsx',
            ),
        ]);

        $upload->assertOk();
        $upload->assertJsonPath('type', 'pelengkap');
        $batchId = $upload->json('batch_id');

        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);
        $validation->assertOk();
        $validation->assertJsonPath('valid_count', 1);
        $validation->assertJsonPath('error_count', 0);

        $execute = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);
        $execute->assertOk();
        $execute->assertJsonPath('inserted', 1);
        $execute->assertJsonPath('failed', 0);

        $employee->refresh();
        $this->assertSame('1234567890123456', $employee->nik);
        $this->assertSame('6543210987654321', $employee->no_kk);
        $this->assertSame('Manado', $employee->tempat_lahir);
        $this->assertSame('L', $employee->jenis_kelamin);
        $this->assertSame('O', $employee->golongan_darah);
        $this->assertNotNull($employee->agama_id);
        $this->assertNotNull($employee->status_kawin_id);

        $this->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSeeText('1234567890123456')
            ->assertSeeText('6543210987654321')
            ->assertSeeText('Manado')
            ->assertSeeText('Laki-laki')
            ->assertSeeText('Islam')
            ->assertSeeText('Menikah')
            ->assertSeeText('O')
            ->assertDontSeeText('3273251203850002')
            ->assertDontSeeText('3273250102120045');
    }

    public function test_import_wizard_executes_history_xlsx_templates(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Siti Aminah',
            'nip' => '198502122010042002',
            'tanggal_lahir' => '1985-02-12',
        ]);

        $this->actingAs($user);

        $rankBatch = $this->uploadValidateAndExecuteTemplate(
            ['NIP', 'Golongan', 'TMT Pangkat', 'No SK', 'Tanggal SK'],
            [[$employee->nip, 'III/a', '2024-01-01', 'SK-PANGKAT-001', '2024-01-15']],
            'template_kepangkatan.xlsx',
        );
        $rankBatch->assertJsonPath('inserted', 1);

        $positionBatch = $this->uploadValidateAndExecuteTemplate(
            ['NIP', 'Nama Jabatan', 'Jenis Jabatan', 'Unit Kerja', 'TMT Jabatan', 'No SK', 'Tanggal SK'],
            [[$employee->nip, 'Analis Kepegawaian', 'Fungsional Umum / Pelaksana', 'Bagian Umum', '2024-02-01', 'SK-JABATAN-001', '2024-02-15']],
            'template_jabatan.xlsx',
        );
        $positionBatch->assertJsonPath('inserted', 1);

        $kgbBatch = $this->uploadValidateAndExecuteTemplate(
            ['NIP', 'TMT KGB', 'Gaji Pokok', 'No SK', 'Tanggal SK'],
            [[$employee->nip, '2024-03-01', '3.500.000', 'SK-KGB-001', '2024-03-15']],
            'template_kgb.xlsx',
        );
        $kgbBatch->assertJsonPath('inserted', 1);

        $this->assertDatabaseHas('rank_histories', [
            'employee_id' => $employee->id,
            'no_sk' => 'SK-PANGKAT-001',
        ]);
        $this->assertDatabaseHas('position_histories', [
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Analis Kepegawaian',
            'no_sk' => 'SK-JABATAN-001',
        ]);
        $this->assertDatabaseHas('salary_histories', [
            'employee_id' => $employee->id,
            'no_sk' => 'SK-KGB-001',
        ]);
        $employee->refresh();
        $this->assertSame('III/a', $employee->golongan_terakhir);
        $this->assertSame('Analis Kepegawaian', $employee->jabatan_terakhir);
        $this->assertSame('2026-03-01', $employee->tanggal_kgb_berikutnya->format('Y-m-d'));

        $this->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSeeText('Penata Muda')
            ->assertSeeText('III/a')
            ->assertSee('SK-PANGKAT-001')
            ->assertSeeText('Analis Kepegawaian')
            ->assertSeeText('Bagian Umum')
            ->assertSee('SK-JABATAN-001')
            ->assertSee('Rp 3.500.000')
            ->assertSee('SK-KGB-001')
            ->assertSeeText('01-03-2026');
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

    private function uploadValidateAndExecuteTemplate(array $headers, array $rows, string $filename)
    {
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->xlsxFileWithHeaders($headers, $rows, $filename),
        ]);

        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $validation = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", []);
        $validation->assertOk();
        $validation->assertJsonPath('valid_count', count($rows));
        $validation->assertJsonPath('error_count', 0);

        return $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
