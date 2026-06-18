<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_create_employee(): void
    {
        $response = $this->postJson('/api/employees', $this->validPayload());

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_create_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->postJson('/api/employees', $this->validPayload());

        $response->assertCreated();
        $response->assertJsonPath('employee.nama_pegawai', 'Budi Santoso');
        $this->assertDatabaseHas('employees', [
            'nama_pegawai' => 'Budi Santoso',
            'email_pegawai' => 'budi@example.com',
            'created_by' => $user->id,
        ]);
    }

    public function test_pegawai_cannot_create_employee(): void
    {
        $user = User::factory()->pegawai()->create();

        $response = $this->actingAs($user)->postJson('/api/employees', $this->validPayload());

        $response->assertForbidden();
    }

    public function test_name_is_required(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $payload = $this->validPayload();
        unset($payload['nama_pegawai']);

        $response = $this->actingAs($user)->postJson('/api/employees', $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('nama_pegawai');
    }

    public function test_duplicate_email_and_nip_are_rejected(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create([
            'email_pegawai' => 'budi@example.com',
            'nip' => '198001012006041001',
        ]);

        $response = $this->actingAs($user)->postJson('/api/employees', $this->validPayload());

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email_pegawai', 'nip']);
    }

    public function test_future_birth_date_is_rejected(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $payload = $this->validPayload();
        $payload['tanggal_lahir'] = now()->addDay()->format('Y-m-d');

        $response = $this->actingAs($user)->postJson('/api/employees', $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('tanggal_lahir');
    }

    private function validPayload(): array
    {
        return [
            'nama_pegawai' => 'Budi Santoso',
            'email_pegawai' => 'budi@example.com',
            'golongan' => 'III/a',
            'jabatan' => 'Analis Kepegawaian',
            'kelas_jabatan' => '7',
            'nip' => '198001012006041001',
            'nomor_telepon' => '081234567890',
            'pangkat' => 'Penata Muda',
            'pendidikan_terakhir' => 'S1',
            'pensiun' => '2038-01-01',
            'person' => 'Budi',
            'person_formula' => 'Budi',
            'prodi_pendidikan_terakhir' => 'Manajemen',
            'status_kepegawaian' => 'PNS',
            'tanggal_lahir' => '1980-01-01',
        ];
    }
}
