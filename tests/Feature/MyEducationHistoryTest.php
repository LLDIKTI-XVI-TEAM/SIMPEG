<?php

namespace Tests\Feature;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\RefJenjangPendidikan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MyEducationHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_pegawai_can_only_list_education_from_their_authenticated_employee(): void
    {
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $jenjang = RefJenjangPendidikan::where('nama', 'D4 / S1')->firstOrFail();

        EducationHistory::create($this->educationPayload($employee, $jenjang, ['nama_institusi' => 'Universitas Saya']));
        EducationHistory::create($this->educationPayload($otherEmployee, $jenjang, ['nama_institusi' => 'Universitas Orang Lain']));

        // Identitas employee harus berasal dari sesi agar riwayat pendidikan pegawai lain tidak ikut terbaca.
        $response = $this->actingAs($user)->getJson(route('api.v1.profil-saya.pendidikan.index'));

        $response->assertOk();
        $response->assertJsonPath('employee_id', $employee->id);
        $response->assertJsonCount(1, 'histories');
        $response->assertJsonPath('histories.0.nama_institusi', 'Universitas Saya');
        $response->assertJsonMissing(['nama_institusi' => 'Universitas Orang Lain']);
    }

    public function test_self_education_mutation_routes_are_absent(): void
    {
        foreach ([
            'api.v1.profil-saya.pendidikan.store',
            'api.v1.profil-saya.pendidikan.update',
            'api.v1.profil-saya.pendidikan.destroy',
        ] as $routeName) {
            $this->assertFalse(Route::has($routeName), "Route {$routeName} tidak boleh tersedia.");
        }
    }

    public function test_pegawai_without_employee_mapping_cannot_read_self_education(): void
    {
        $user = User::factory()->pegawai()->create(['employee_id' => null]);

        $this->actingAsUnmapped($user)
            ->getJson(route('api.v1.profil-saya.pendidikan.index'))
            ->assertRedirect(route('status-akun'));
    }

    public function test_pegawai_without_education_read_permission_cannot_read_self_education(): void
    {
        $role = Role::where('name', 'pegawai')->firstOrFail();
        $permission = Permission::where('name', 'employee_histories.read')->firstOrFail();
        $role->permissions()->detach($permission->id);
        $user = User::factory()->pegawai()->create(['employee_id' => Employee::factory()->create()->id]);

        $this->actingAs($user)
            ->getJson(route('api.v1.profil-saya.pendidikan.index'))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function educationPayload(
        Employee $employee,
        RefJenjangPendidikan $jenjang,
        array $overrides = [],
    ): array {
        return array_merge([
            'employee_id' => $employee->id,
            'jenjang_id' => $jenjang->id,
            'nama_institusi' => 'Universitas Contoh',
            'jurusan' => 'Administrasi Publik',
            'tahun_lulus' => 2010,
            'no_ijazah' => 'IJZ-001',
        ], $overrides);
    }
}
