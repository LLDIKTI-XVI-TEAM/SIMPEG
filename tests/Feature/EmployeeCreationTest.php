<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\RefProgramStudi;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeCreationTest extends TestCase
{
    use RefreshDatabase;

    private const EMPLOYEES_ENDPOINT = '/api/v1/pegawai';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        // Seed RBAC agar permission employees.create tersedia untuk middleware permission.
        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_create_employee(): void
    {
        $response = $this->postJsonWithCsrf(self::EMPLOYEES_ENDPOINT, $this->validPayload());

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_create_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEES_ENDPOINT, $this->validPayload());

        $response->assertCreated();
        $response->assertJsonPath('employee.nama_lengkap', 'Budi Santoso');
        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Budi Santoso',
            'email_pribadi' => 'budi@example.com',
            'nip' => '198001012006041001',
            'tanggal_pensiun' => '2038-01-01 00:00:00',
            'jenis_pegawai_id' => RefJenisPegawai::where('nama', 'PNS')->firstOrFail()->id,
        ]);
    }

    public function test_authenticated_user_create_employee_writes_audit_log(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEES_ENDPOINT, $this->validPayload());

        $response->assertCreated();
        $employeeId = $response->json('employee.id');

        $audit = AuditLog::where('event', 'CREATE')
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employeeId)
            ->firstOrFail();

        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('Budi Santoso', $audit->new_values['nama_lengkap']);
        $this->assertSame('198001012006041001', $audit->new_values['nip']);
    }

    public function test_authenticated_user_can_create_employee_with_photo_upload(): void
    {

        $user = User::factory()->adminKepegawaian()->create();
        $payload = $this->validPayload([
            'foto' => UploadedFile::fake()->image('foto-valid.jpg', 640, 640)->size(512),
        ]);

        $this->actingAs($user);
        $response = $this->postWithCsrf(self::EMPLOYEES_ENDPOINT, $payload);

        $response->assertCreated();
        $photoPath = $response->json('employee.foto');
        $this->assertIsString($photoPath);
        $this->assertStringStartsWith('employees/photos/', $photoPath);
        $this->assertStringEndsWith('.jpg', $photoPath);
        Storage::disk('public')->assertExists($photoPath);
        $this->assertDatabaseHas('employees', [
            'nip' => '198001012006041001',
            'foto' => $photoPath,
        ]);
    }

    public function test_photo_upload_rejects_disallowed_extension_even_when_content_is_image(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $payload = $this->validPayload([
            'foto' => UploadedFile::fake()->image('foto-invalid.gif', 640, 640)->size(512),
        ]);

        $this->actingAs($user);
        $response = $this->postWithCsrf(self::EMPLOYEES_ENDPOINT, $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['foto']);
    }

    public function test_pegawai_cannot_create_employee(): void
    {
        $user = User::factory()->pegawai()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEES_ENDPOINT, $this->validPayload());

        $response->assertForbidden();
    }

    public function test_name_is_required(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $payload = $this->validPayload();
        unset($payload['nama_lengkap']);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEES_ENDPOINT, $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('nama_lengkap');
    }

    public function test_duplicate_email_and_nip_are_rejected(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create([
            'email_pribadi' => 'budi@example.com',
            'nip' => '198001012006041001',
        ]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEES_ENDPOINT, $this->validPayload());

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email_pribadi', 'nip']);
    }

    public function test_future_birth_date_is_rejected(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $payload = $this->validPayload();
        $payload['tanggal_lahir'] = now()->addDay()->format('Y-m-d');

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::EMPLOYEES_ENDPOINT, $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('tanggal_lahir');
    }

    public function test_authenticated_user_cannot_create_employee_with_inactive_program_studi(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $programStudi = RefProgramStudi::create([
            'nama' => 'Program Studi Nonaktif',
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->postJsonWithCsrf(self::EMPLOYEES_ENDPOINT, $this->validPayload([
            'program_studi_id' => $programStudi->id,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors('program_studi_id');
        $this->assertDatabaseMissing('employees', ['nip' => '198001012006041001']);
    }

    public function test_old_employees_store_endpoint_is_not_available(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf('/api/v1/employees', $this->validPayload());

        $response->assertNotFound();
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function postWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token', 'Accept' => 'application/json']);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nama_lengkap' => 'Budi Santoso',
            'email_pribadi' => 'budi@example.com',
            'golongan_terakhir' => 'III/a',
            'jabatan_terakhir' => 'Analis Kepegawaian',
            'kelas_jabatan_terakhir' => '7',
            'nip' => '198001012006041001',
            'no_hp' => '081234567890',
            'pangkat_terakhir' => 'Penata Muda',
            'pendidikan_terakhir' => 'S1',
            'tanggal_pensiun' => '2038-01-01',
            'prodi_pendidikan_terakhir' => 'Manajemen',
            'jenis_pegawai_id' => RefJenisPegawai::where('nama', 'PNS')->firstOrFail()->id,
            'tanggal_lahir' => '1980-01-01',
        ], $overrides);
    }
}
