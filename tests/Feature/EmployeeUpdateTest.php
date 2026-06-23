<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_update_employee(): void
    {
        $employee = Employee::factory()->create();

        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee));

        $response->assertRedirect('/login');
    }

    public function test_admin_kepegawaian_can_update_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Nama Lama',
            'email' => 'lama@example.com',
            'nip' => '198001012006041001',
        ]);

        $payload = $this->validPayload($employee, [
            'nama_lengkap' => 'Nama Baru',
            'email' => 'baru@example.com',
            'jabatan_terakhir' => 'Analis SDM Aparatur',
        ]);

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $payload);

        $response->assertOk();
        $response->assertJsonPath('message', 'Data pegawai berhasil diperbarui.');
        $response->assertJsonPath('employee.nama_lengkap', 'Nama Baru');
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'nama_lengkap' => 'Nama Baru',
            'email' => 'baru@example.com',
            'jabatan_terakhir' => 'Analis SDM Aparatur',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_super_admin_can_update_employee(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'nama_lengkap' => 'Diperbarui Super Admin',
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'nama_lengkap' => 'Diperbarui Super Admin',
        ]);
    }

    public function test_pegawai_cannot_update_employee(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee));

        $response->assertForbidden();
    }

    public function test_same_nip_and_email_are_allowed_for_current_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'email' => 'tetap@example.com',
            'nip' => '198001012006041001',
        ]);

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'nama_lengkap' => 'Nama Tetap Valid',
            'email' => 'tetap@example.com',
            'nip' => '198001012006041001',
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'nama_lengkap' => 'Nama Tetap Valid',
            'email' => 'tetap@example.com',
            'nip' => '198001012006041001',
        ]);
    }

    public function test_duplicate_email_and_nip_are_rejected_for_other_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        Employee::factory()->create([
            'email' => 'duplikat@example.com',
            'nip' => '198001012006041001',
        ]);

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'email' => 'duplikat@example.com',
            'nip' => '198001012006041001',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email', 'nip']);
    }

    public function test_future_birth_date_is_rejected(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee), $this->validPayload($employee, [
            'tanggal_lahir' => now()->addDay()->format('Y-m-d'),
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('tanggal_lahir');
    }

    private function endpoint(Employee $employee): string
    {
        return "/api/v1/pegawai/{$employee->id}";
    }

    public function test_old_employees_update_endpoint_is_not_available(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf("/api/v1/employees/{$employee->id}", $this->validPayload($employee));

        $response->assertNotFound();
    }

    private function putJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->putJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function validPayload(Employee $employee, array $overrides = []): array
    {
        return array_merge([
            'nama_lengkap' => $employee->nama_lengkap,
            'email' => $employee->email,
            'golongan_terakhir' => $employee->golongan_terakhir,
            'jabatan_terakhir' => $employee->jabatan_terakhir,
            'kelas_jabatan' => $employee->kelas_jabatan,
            'nip' => $employee->nip,
            'no_hp' => $employee->no_hp,
            'pangkat_terakhir' => $employee->pangkat_terakhir,
            'pendidikan_terakhir' => $employee->pendidikan_terakhir,
            'tanggal_pensiun' => $employee->tanggal_pensiun?->format('Y-m-d'),
            'prodi_pendidikan_terakhir' => $employee->prodi_pendidikan_terakhir,
            'jenis_pegawai_id' => $employee->jenis_pegawai_id ?: RefJenisPegawai::where('nama', 'PNS')->firstOrFail()->id,
            'tanggal_lahir' => $employee->tanggal_lahir->format('Y-m-d'),
        ], $overrides);
    }
}
