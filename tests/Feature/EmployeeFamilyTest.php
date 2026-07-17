<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class EmployeeFamilyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_admin_can_list_employee_families_ordered_newest_first(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $oldFamily = EmployeeFamily::create($this->familyPayload($employee, [
            'nama_anggota' => 'Anak Lama',
        ]));
        $oldFamily->forceFill(['created_at' => '2026-06-01 08:00:00'])->save();
        $newFamily = EmployeeFamily::create($this->familyPayload($employee, [
            'nama_anggota' => 'Anak Baru',
        ]));
        $newFamily->forceFill(['created_at' => '2026-06-02 08:00:00'])->save();

        $this->actingAs($user);
        $response = $this->getJson($this->endpoint($employee));

        $response->assertOk();
        $response->assertJsonPath('employee_id', $employee->id);
        $response->assertJsonCount(2, 'families');
        $response->assertJsonPath('families.0.nama_anggota', 'Anak Baru');
        $response->assertJsonPath('families.1.nama_anggota', 'Anak Lama');
        $response->assertJsonMissingPath('families.0.employee');
    }

    public function test_admin_can_create_family_and_write_audit_log(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf($this->endpoint($employee), $this->validPayload([
            'nama_anggota' => 'Siti Keluarga',
        ]));

        $response->assertCreated();
        $response->assertJsonPath('message', 'Data keluarga berhasil ditambahkan.');
        $response->assertJsonPath('family.nama_anggota', 'Siti Keluarga');
        $this->assertDatabaseHas('employee_families', [
            'employee_id' => $employee->id,
            'nama_anggota' => 'Siti Keluarga',
            'hubungan' => 'Istri',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CREATE',
            'auditable_type' => 'EmployeeFamily',
        ]);
        $audit = AuditLog::where('auditable_type', 'EmployeeFamily')->firstOrFail();

        $this->assertSame('Siti Keluarga', Arr::get($audit->new_values, 'nama_anggota'));
        $this->assertArrayNotHasKey('nik', $audit->new_values);
    }

    public function test_admin_can_update_family_and_write_audit_log(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $family = EmployeeFamily::create($this->familyPayload($employee, [
            'nama_anggota' => 'Nama Lama',
        ]));

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf($this->endpoint($employee)."/{$family->id}", $this->validPayload([
            'nama_anggota' => 'Nama Baru',
            'hubungan' => 'Anak',
            'jenis_kelamin' => 'L',
        ]));

        $response->assertOk();
        $response->assertJsonPath('message', 'Data keluarga berhasil diperbarui.');
        $response->assertJsonPath('family.nama_anggota', 'Nama Baru');
        $this->assertDatabaseHas('employee_families', [
            'id' => $family->id,
            'nama_anggota' => 'Nama Baru',
            'hubungan' => 'Anak',
        ]);
        $audit = AuditLog::where('event', 'UPDATE')
            ->where('auditable_type', 'EmployeeFamily')
            ->firstOrFail();

        $this->assertSame('Nama Lama', Arr::get($audit->old_values, 'nama_anggota'));
        $this->assertSame('Nama Baru', Arr::get($audit->new_values, 'nama_anggota'));
        $this->assertArrayNotHasKey('nik', $audit->old_values);
        $this->assertArrayNotHasKey('nik', $audit->new_values);
    }

    public function test_admin_can_permanently_delete_family_and_write_audit_log(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $family = EmployeeFamily::create($this->familyPayload($employee));

        $this->actingAs($user);
        $response = $this->deleteJsonWithCsrf($this->endpoint($employee)."/{$family->id}");

        $response->assertOk();
        $response->assertJsonPath('message', 'Data keluarga berhasil dihapus.');
        $this->assertDatabaseMissing('employee_families', ['id' => $family->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'EmployeeFamily',
            'auditable_id' => $family->id,
        ]);
    }

    public function test_soft_deleted_families_are_hidden_from_index(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $activeFamily = EmployeeFamily::create($this->familyPayload($employee, ['nama_anggota' => 'Aktif']));
        $deletedFamily = EmployeeFamily::create($this->familyPayload($employee, ['nama_anggota' => 'Nonaktif']));
        $deletedFamily->delete();

        $this->actingAs($user);
        $response = $this->getJson($this->endpoint($employee));

        $response->assertOk();
        $response->assertJsonCount(1, 'families');
        $response->assertJsonPath('families.0.id', $activeFamily->id);
        $response->assertJsonMissing(['id' => $deletedFamily->id]);
    }

    public function test_pegawai_cannot_access_admin_family_crud_endpoints(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = Employee::factory()->create();
        $family = EmployeeFamily::create($this->familyPayload($employee));

        $this->actingAs($user);

        $this->getJson($this->endpoint($employee))->assertForbidden();
        $this->postJsonWithCsrf($this->endpoint($employee), $this->validPayload())->assertForbidden();
        $this->putJsonWithCsrf($this->endpoint($employee)."/{$family->id}", $this->validPayload())->assertForbidden();
        $this->deleteJsonWithCsrf($this->endpoint($employee)."/{$family->id}")->assertForbidden();
    }

    public function test_permission_removal_forbids_family_crud(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $role->permissions()->detach(Permission::whereIn('name', [
            'employee_families.read',
            'employee_families.create',
            'employee_families.update',
            'employee_families.delete',
        ])->pluck('id'));
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $family = EmployeeFamily::create($this->familyPayload($employee));

        $this->actingAs($user);

        $this->getJson($this->endpoint($employee))->assertForbidden();
        $this->postJsonWithCsrf($this->endpoint($employee), $this->validPayload())->assertForbidden();
        $this->putJsonWithCsrf($this->endpoint($employee)."/{$family->id}", $this->validPayload())->assertForbidden();
        $this->deleteJsonWithCsrf($this->endpoint($employee)."/{$family->id}")->assertForbidden();
    }

    public function test_validation_rejects_invalid_family_payload(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf($this->endpoint($employee), [
            'nama_anggota' => '',
            'hubungan' => 'Tetangga',
            'nik' => '123',
            'tempat_lahir' => str_repeat('A', 101),
            'tanggal_lahir' => now()->addDay()->format('Y-m-d'),
            'jenis_kelamin' => 'X',
            'status_tunjangan' => 'mungkin',
            'pekerjaan' => str_repeat('B', 101),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'nama_anggota',
            'hubungan',
            'nik',
            'tempat_lahir',
            'tanggal_lahir',
            'jenis_kelamin',
            'status_tunjangan',
            'pekerjaan',
        ]);
    }

    public function test_family_route_rejects_family_from_different_employee(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $family = EmployeeFamily::create($this->familyPayload($otherEmployee));

        $this->actingAs($user);

        $this->putJsonWithCsrf($this->endpoint($employee)."/{$family->id}", $this->validPayload())->assertNotFound();
        $this->deleteJsonWithCsrf($this->endpoint($employee)."/{$family->id}")->assertNotFound();
    }

    private function endpoint(Employee $employee): string
    {
        return "/api/v1/pegawai/{$employee->id}/keluarga";
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function putJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->putJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function deleteJsonWithCsrf(string $uri)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->deleteJson($uri, [], ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nama_anggota' => 'Siti Keluarga',
            'hubungan' => 'Istri',
            'nik' => '7171010101010001',
            'tempat_lahir' => 'Manado',
            'tanggal_lahir' => '1990-05-10',
            'jenis_kelamin' => 'P',
            'status_tunjangan' => true,
            'pekerjaan' => 'Guru',
        ], $overrides);
    }

    private function familyPayload(Employee $employee, array $overrides = []): array
    {
        return array_merge($this->validPayload(), [
            'employee_id' => $employee->id,
        ], $overrides);
    }
}
