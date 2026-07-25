<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeSupervisorLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_hanya_pengelola_pegawai_dengan_permission_perubahan_yang_dapat_mencari_kepala_bagian(): void
    {
        $target = Employee::factory()->create();
        $candidate = Employee::factory()->create([
            'nama_lengkap' => 'Kepala Bagian Lookup',
            'nip' => '198765432100000001',
        ]);

        foreach (['super_admin', 'admin_kepegawaian'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson(route('pegawai.supervisor-lookup', ['id' => $target->id, 'q' => 'Lookup']))
                ->assertOk()
                ->assertExactJson([
                    'data' => [[
                        'id' => $candidate->id,
                        'nama_lengkap' => 'Kepala Bagian Lookup',
                        'nip' => '198765432100000001',
                    ]],
                ]);
        }

        $this->actingAs(User::factory()->pegawai()->create())
            ->getJson(route('pegawai.supervisor-lookup', ['id' => $target->id, 'q' => 'Lookup']))
            ->assertForbidden();

        $role = Role::where('name', 'super_admin')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.update')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson(route('pegawai.supervisor-lookup', ['id' => $target->id, 'q' => 'Lookup']))
            ->assertForbidden();
    }

    public function test_lookup_dibatasi_lima_belas_hasil_dan_mengecualikan_pegawai_target(): void
    {
        $target = Employee::factory()->create([
            'nama_lengkap' => 'Target Kepala Bagian',
            'nip' => '198765432100000099',
        ]);

        foreach (range(16, 1) as $number) {
            Employee::factory()->create([
                'nama_lengkap' => sprintf('Kepala Bagian %02d', $number),
            ]);
        }

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson(route('pegawai.supervisor-lookup', ['id' => $target->id, 'q' => 'Kepala Bagian']))
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('data.0.nama_lengkap', 'Kepala Bagian 01')
            ->assertJsonMissing(['id' => $target->id])
            ->assertJsonMissing(['nama_lengkap' => 'Kepala Bagian 16']);
    }

    public function test_lookup_tidak_memfilter_status_pegawai_atau_status_aktif(): void
    {
        $target = Employee::factory()->create();
        $candidate = Employee::factory()->create([
            'nama_lengkap' => 'Kandidat Status Berbeda',
            'nip' => '198765432100000002',
            'status_aktif' => 'Non-Aktif',
            'role' => 'pimpinan',
        ]);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->getJson(route('pegawai.supervisor-lookup', ['id' => $target->id, 'q' => 'Status Berbeda']))
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $candidate->id,
                    'nama_lengkap' => 'Kandidat Status Berbeda',
                    'nip' => '198765432100000002',
                ]],
            ]);
    }

    public function test_lookup_memvalidasi_query_setelah_merapikan_spasi(): void
    {
        $target = Employee::factory()->create();
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)
            ->getJson(route('pegawai.supervisor-lookup', ['id' => $target->id, 'q' => ' P ']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');

        $this->actingAs($actor)
            ->getJson(route('pegawai.supervisor-lookup', ['id' => $target->id, 'q' => str_repeat('P', 101)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }
}
