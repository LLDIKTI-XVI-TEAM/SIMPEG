<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    private const UPLOAD_ENDPOINT = '/api/pegawai/import/upload';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_upload_employee_import(): void
    {
        $response = $this->postJsonWithCsrf(self::UPLOAD_ENDPOINT, [
            'file' => $this->csvFile($this->validCsv()),
        ]);

        $response->assertRedirect('/login');
    }

    public function test_admin_can_complete_valid_import_workflow(): void
    {
        $this->signInAsAdmin();

        $batchId = $this->uploadCsv($this->validCsv());

        $this->getJson("/api/pegawai/import/{$batchId}/preview")
            ->assertOk()
            ->assertJsonPath('total_rows', 2);

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate")
            ->assertOk()
            ->assertJsonPath('valid_count', 2)
            ->assertJsonPath('error_count', 0)
            ->assertJsonPath('skip_count', 0);

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute")
            ->assertOk()
            ->assertJsonPath('inserted', 2)
            ->assertJsonPath('failed', 0)
            ->assertJsonPath('skipped', 0);

        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'nip' => '198001012006041001',
            'jenis_pegawai_id' => RefJenisPegawai::where('nama', 'PNS')->firstOrFail()->id,
        ]);
        $this->assertSame(
            '1985-02-12',
            Employee::where('nama_lengkap', 'Siti Aminah')->firstOrFail()->tanggal_lahir->format('Y-m-d'),
        );
    }

    public function test_pegawai_cannot_upload_employee_import(): void
    {
        $user = User::factory()->pegawai()->create();
        $this->actingAs($user)->withSession(['active_role' => 'pegawai']);

        $this->postJsonWithCsrf(self::UPLOAD_ENDPOINT, [
            'file' => $this->csvFile($this->validCsv()),
        ])->assertForbidden();
    }

    public function test_upload_rejects_missing_header(): void
    {
        $this->signInAsAdmin();

        $this->postJsonWithCsrf(self::UPLOAD_ENDPOINT, [
            'file' => $this->csvFile("Nama Pegawai,Email Pegawai\nBudi,budi@example.com\n"),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_validation_reports_unknown_employee_type_and_execute_imports_only_valid_rows(): void
    {
        $this->signInAsAdmin();
        $csv = str_replace(',PNS,1980-01-01', ',HONORER,1980-01-01', $this->validCsv());
        $batchId = $this->uploadCsv($csv);

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate")
            ->assertOk()
            ->assertJsonPath('valid_count', 1)
            ->assertJsonPath('error_count', 1)
            ->assertJsonPath('results.0.status', 'error')
            ->assertJsonPath('results.0.row', 2);

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute")
            ->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('failed', 1);

        $this->assertDatabaseMissing('employees', ['nama_lengkap' => 'Budi Santoso']);
        $this->assertDatabaseHas('employees', ['nama_lengkap' => 'Siti Aminah']);
    }

    public function test_existing_nip_is_skipped_instead_of_reported_as_error(): void
    {
        $this->signInAsAdmin();
        Employee::factory()->create([
            'nip' => '198001012006041001',
            'email' => 'existing@example.com',
        ]);
        $batchId = $this->uploadCsv($this->validCsv());

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate")
            ->assertOk()
            ->assertJsonPath('valid_count', 1)
            ->assertJsonPath('error_count', 0)
            ->assertJsonPath('skip_count', 1)
            ->assertJsonPath('results.0.status', 'skip');

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute")
            ->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('failed', 0);

        $this->assertSame(1, Employee::where('nip', '198001012006041001')->count());
        $this->assertDatabaseHas('employees', ['nama_lengkap' => 'Siti Aminah']);
    }

    public function test_existing_email_is_reported_as_error(): void
    {
        $this->signInAsAdmin();
        Employee::factory()->create([
            'nip' => '199001012020041001',
            'email' => 'BUDI@example.com',
        ]);
        $batchId = $this->uploadCsv($this->validCsv());

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate")
            ->assertOk()
            ->assertJsonPath('valid_count', 1)
            ->assertJsonPath('error_count', 1)
            ->assertJsonPath('results.0.status', 'error')
            ->assertJsonPath('results.0.errors.email.0', 'Email pegawai sudah terdaftar di database.');
    }

    public function test_duplicate_rows_inside_file_are_reported_without_blocking_valid_rows(): void
    {
        $this->signInAsAdmin();
        $csv = $this->validCsv().implode(',', [
            'Siti Duplikat',
            'siti@example.com',
            'III/c',
            'Analis Kepegawaian',
            '8',
            '198502122010042002',
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
        $batchId = $this->uploadCsv($csv);

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate")
            ->assertOk()
            ->assertJsonPath('valid_count', 2)
            ->assertJsonPath('error_count', 1)
            ->assertJsonPath('results.2.status', 'error')
            ->assertJsonPath('results.2.row', 4);

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute")
            ->assertOk()
            ->assertJsonPath('inserted', 2)
            ->assertJsonPath('failed', 1);

        $this->assertDatabaseCount('employees', 2);
    }

    public function test_upload_rejects_xlsx_file_with_truthful_message(): void
    {
        $this->signInAsAdmin();

        $this->postJsonWithCsrf(self::UPLOAD_ENDPOINT, [
            'file' => UploadedFile::fake()->create(
                'pegawai.xlsx',
                10,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('file')
            ->assertJsonPath('errors.file.0', 'Format yang didukung saat ini hanya CSV.');
    }

    public function test_validation_rejects_row_without_nip(): void
    {
        $this->signInAsAdmin();
        $csv = str_replace(',198001012006041001,', ',,', $this->validCsv());
        $batchId = $this->uploadCsv($csv);

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate")
            ->assertOk()
            ->assertJsonPath('error_count', 1)
            ->assertJsonPath('results.0.status', 'error')
            ->assertJsonPath('results.0.row', 2);
    }

    public function test_validation_rejects_row_without_employee_type(): void
    {
        $this->signInAsAdmin();
        $csv = str_replace(',PNS,1980-01-01', ',,1980-01-01', $this->validCsv());
        $batchId = $this->uploadCsv($csv);

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate")
            ->assertOk()
            ->assertJsonPath('error_count', 1)
            ->assertJsonPath('results.0.status', 'error')
            ->assertJsonPath('results.0.row', 2);
    }

    public function test_execute_requires_completed_validation(): void
    {
        $this->signInAsAdmin();
        $batchId = $this->uploadCsv($this->validCsv());

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Data belum divalidasi. Jalankan validasi terlebih dahulu.');
    }

    public function test_batch_cannot_be_accessed_by_another_user(): void
    {
        $this->signInAsAdmin();
        $batchId = $this->uploadCsv($this->validCsv());

        $otherAdmin = User::factory()->adminKepegawaian()->create();
        $this->actingAs($otherAdmin)->withSession(['active_role' => 'admin_kepegawaian']);

        $this->getJson("/api/pegawai/import/{$batchId}/preview")->assertForbidden();
    }

    public function test_edited_rows_payload_must_keep_expected_structure(): void
    {
        $this->signInAsAdmin();
        $batchId = $this->uploadCsv($this->validCsv());

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", [
            'rows' => [['row' => 'not-a-number']],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['rows.0.row', 'rows.0.data']);
    }

    private function signInAsAdmin(): User
    {
        $user = User::factory()->adminKepegawaian()->create();
        $this->actingAs($user)->withSession(['active_role' => 'admin_kepegawaian']);

        return $user;
    }

    private function uploadCsv(string $csv): string
    {
        $response = $this->postJsonWithCsrf(self::UPLOAD_ENDPOINT, [
            'file' => $this->csvFile($csv),
        ]);

        $response->assertOk();

        return $response->json('batch_id');
    }

    private function validCsv(): string
    {
        return implode(',', [
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
        ])."\n".
        implode(',', [
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
        ])."\n".
        implode(',', [
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
        ])."\n";
    }

    private function csvFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'employees');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'employees.csv', 'text/csv', null, true);
    }

    private function postJsonWithCsrf(string $uri, array $data = [])
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
