<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class MyFamilyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    // ──────────────────────────────────────────────
    // GET /api/v1/profil-saya/keluarga
    // ──────────────────────────────────────────────

    public function test_pegawai_can_list_their_own_families(): void
    {
        $employee = Employee::factory()->create();
        $user     = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        EmployeeFamily::create($this->familyPayload($employee, ['nama_anggota' => 'Istri Saya']));
        EmployeeFamily::create($this->familyPayload($employee, ['nama_anggota' => 'Anak Saya', 'hubungan' => 'Anak', 'jenis_kelamin' => 'L']));

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya/keluarga');

        $response->assertOk();
        $response->assertJsonPath('employee_id', $employee->id);
        $response->assertJsonCount(2, 'families');
    }

    public function test_pegawai_cannot_see_families_of_other_employees(): void
    {
        $myEmployee    = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $user          = User::factory()->pegawai()->create(['employee_id' => $myEmployee->id]);

        EmployeeFamily::create($this->familyPayload($otherEmployee, ['nama_anggota' => 'Keluarga Orang Lain']));

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya/keluarga');

        $response->assertOk();
        $response->assertJsonCount(0, 'families');
    }

    // ──────────────────────────────────────────────
    // POST /api/v1/profil-saya/keluarga
    // ──────────────────────────────────────────────

    public function test_pegawai_can_add_family_member_and_write_audit_log(): void
    {
        $employee = Employee::factory()->create();
        $user     = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf('/api/v1/profil-saya/keluarga', $this->validPayload([
            'nama_anggota' => 'Budi Anak Saya',
            'hubungan'     => 'Anak',
            'jenis_kelamin' => 'L',
        ]));

        $response->assertCreated();
        $response->assertJsonPath('message', 'Data keluarga berhasil ditambahkan.');
        $response->assertJsonPath('family.nama_anggota', 'Budi Anak Saya');
        $response->assertJsonPath('family.employee_id', $employee->id);
        $this->assertDatabaseHas('employee_families', [
            'employee_id'  => $employee->id,
            'nama_anggota' => 'Budi Anak Saya',
            'hubungan'     => 'Anak',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event'          => 'CREATE',
            'auditable_type' => 'EmployeeFamily',
        ]);
    }

    public function test_pegawai_can_add_family_member_with_hubungan_saudara(): void
    {
        $employee = Employee::factory()->create();
        $user     = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf('/api/v1/profil-saya/keluarga', $this->validPayload([
            'nama_anggota' => 'Saudara Saya',
            'hubungan'     => 'Saudara',
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('employee_families', [
            'employee_id' => $employee->id,
            'hubungan'    => 'Saudara',
        ]);
    }

    public function test_pegawai_without_employee_mapping_is_forbidden(): void
    {
        // User pegawai yang belum dipetakan ke data employee (employee_id null)
        $user = User::factory()->pegawai()->create(['employee_id' => null]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf('/api/v1/profil-saya/keluarga', $this->validPayload());

        $response->assertForbidden();
    }

    public function test_admin_kepegawaian_cannot_use_self_service_endpoint(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya/keluarga');

        $response->assertForbidden();
    }

    // ──────────────────────────────────────────────
    // PUT /api/v1/profil-saya/keluarga/{family}
    // ──────────────────────────────────────────────

    public function test_pegawai_can_update_their_own_family_member(): void
    {
        $employee = Employee::factory()->create();
        $user     = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $family   = EmployeeFamily::create($this->familyPayload($employee, ['nama_anggota' => 'Nama Lama']));

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf("/api/v1/profil-saya/keluarga/{$family->id}", $this->validPayload([
            'nama_anggota' => 'Nama Baru',
        ]));

        $response->assertOk();
        $response->assertJsonPath('message', 'Data keluarga berhasil diperbarui.');
        $response->assertJsonPath('family.nama_anggota', 'Nama Baru');
        $this->assertDatabaseHas('employee_families', [
            'id'           => $family->id,
            'nama_anggota' => 'Nama Baru',
        ]);
        $audit = AuditLog::where('event', 'UPDATE')
            ->where('auditable_type', 'EmployeeFamily')
            ->firstOrFail();
        $this->assertSame('Nama Lama', Arr::get($audit->old_values, 'nama_anggota'));
        $this->assertSame('Nama Baru', Arr::get($audit->new_values, 'nama_anggota'));
    }

    public function test_pegawai_cannot_update_family_of_another_employee(): void
    {
        $myEmployee    = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $user          = User::factory()->pegawai()->create(['employee_id' => $myEmployee->id]);
        $otherFamily   = EmployeeFamily::create($this->familyPayload($otherEmployee));

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf("/api/v1/profil-saya/keluarga/{$otherFamily->id}", $this->validPayload());

        $response->assertForbidden();
    }

    public function test_validation_rejects_invalid_self_service_payload(): void
    {
        $employee = Employee::factory()->create();
        $user     = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf('/api/v1/profil-saya/keluarga', [
            'nama_anggota'    => '',
            'hubungan'        => 'Tetangga',
            'nik'             => '123',
            'tanggal_lahir'   => now()->addDay()->format('Y-m-d'),
            'jenis_kelamin'   => 'X',
            'status_tunjangan' => 'mungkin',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'nama_anggota',
            'hubungan',
            'nik',
            'tanggal_lahir',
            'jenis_kelamin',
            'status_tunjangan',
        ]);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function postJsonWithCsrf(string $uri, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function putJsonWithCsrf(string $uri, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->putJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nama_anggota'     => 'Siti Keluarga',
            'hubungan'         => 'Istri',
            'nik'              => '7171010101010001',
            'tempat_lahir'     => 'Manado',
            'tanggal_lahir'    => '1990-05-10',
            'jenis_kelamin'    => 'P',
            'status_tunjangan' => true,
            'pekerjaan'        => 'Guru',
        ], $overrides);
    }

    private function familyPayload(Employee $employee, array $overrides = []): array
    {
        return array_merge($this->validPayload(), [
            'employee_id' => $employee->id,
        ], $overrides);
    }
}
