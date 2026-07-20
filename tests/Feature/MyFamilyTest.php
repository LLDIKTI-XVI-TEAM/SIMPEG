<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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

    public function test_pegawai_can_only_list_families_from_their_authenticated_employee(): void
    {
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        EmployeeFamily::create($this->familyPayload($employee, ['nama_anggota' => 'Keluarga Saya']));
        EmployeeFamily::create($this->familyPayload($otherEmployee, ['nama_anggota' => 'Keluarga Orang Lain']));

        // Identitas employee harus berasal dari sesi agar parameter milik pegawai lain tidak dapat disisipkan.
        $response = $this->actingAs($user)->getJson(route('api.v1.profil-saya.keluarga.index'));

        $response->assertOk();
        $response->assertJsonPath('employee_id', $employee->id);
        $response->assertJsonCount(1, 'families');
        $response->assertJsonPath('families.0.nama_anggota', 'Keluarga Saya');
        $response->assertJsonMissing(['nama_anggota' => 'Keluarga Orang Lain']);
    }

    public function test_self_family_mutation_routes_are_absent(): void
    {
        foreach ([
            'api.v1.profil-saya.keluarga.store',
            'api.v1.profil-saya.keluarga.update',
            'api.v1.profil-saya.keluarga.destroy',
        ] as $routeName) {
            $this->assertFalse(Route::has($routeName), "Route {$routeName} tidak boleh tersedia.");
        }
    }

    public function test_pegawai_without_employee_mapping_cannot_read_self_family(): void
    {
        $user = User::factory()->pegawai()->create(['employee_id' => null]);

        $this->actingAs($user)
            ->getJson(route('api.v1.profil-saya.keluarga.index'))
            ->assertNotFound();
    }

    public function test_pegawai_without_family_read_permission_cannot_read_self_family(): void
    {
        $role = Role::where('name', 'pegawai')->firstOrFail();
        $role->permissions()->detach(
            Permission::where('name', 'employee_families.read')->firstOrFail()->id,
        );
        $user = User::factory()->pegawai()->create(['employee_id' => Employee::factory()->create()->id]);

        $this->actingAs($user)
            ->getJson(route('api.v1.profil-saya.keluarga.index'))
            ->assertForbidden();
    }

    public function test_admin_kepegawaian_cannot_use_self_family_endpoint(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->getJson(route('api.v1.profil-saya.keluarga.index'))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function familyPayload(Employee $employee, array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $employee->id,
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
}
