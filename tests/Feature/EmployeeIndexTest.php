<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\RefJenisPegawai;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeIndexTest extends TestCase
{
    use RefreshDatabase;

    private const PEGAWAI_ENDPOINT = '/api/v1/pegawai';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_list_employees(): void
    {
        $response = $this->getJson(self::PEGAWAI_ENDPOINT);

        $response->assertRedirect('/login');
    }

    public function test_admin_kepegawaian_can_list_employees(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Budi Santoso']);

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT);

        $response->assertOk();
        $response->assertJsonPath('message', 'Daftar pegawai berhasil diambil.');
        $response->assertJsonPath('employees.data.0.nama_lengkap', 'Budi Santoso');
    }

    public function test_super_admin_can_list_employees(): void
    {
        $user = User::factory()->superAdmin()->create();
        Employee::factory()->create(['nama_lengkap' => 'Admin View']);

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT);

        $response->assertOk();
        $response->assertJsonPath('employees.data.0.nama_lengkap', 'Admin View');
    }

    public function test_pegawai_cannot_list_employees(): void
    {
        $user = User::factory()->pegawai()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT);

        $response->assertForbidden();
    }

    public function test_default_only_lists_active_employees(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Aktif', 'status_aktif' => 'Aktif']);
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Nonaktif', 'status_aktif' => 'Non-Aktif']);

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT);

        $response->assertOk();
        $response->assertJsonCount(1, 'employees.data');
        $response->assertJsonPath('employees.data.0.nama_lengkap', 'Pegawai Aktif');
    }

    public function test_search_matches_name_and_nip(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create([
            'nama_lengkap' => 'Siti Aminah',
            'nip' => '198001012006041001',
        ]);
        Employee::factory()->create([
            'nama_lengkap' => 'Budi Santoso',
            'nip' => '197701012006041002',
        ]);

        $this->actingAs($user);

        $responseByName = $this->getJson(self::PEGAWAI_ENDPOINT.'?search=aminah');
        $responseByName->assertOk();
        $responseByName->assertJsonCount(1, 'employees.data');
        $responseByName->assertJsonPath('employees.data.0.nama_lengkap', 'Siti Aminah');

        $responseByNip = $this->getJson(self::PEGAWAI_ENDPOINT.'?search=197701012006041002');
        $responseByNip->assertOk();
        $responseByNip->assertJsonCount(1, 'employees.data');
        $responseByNip->assertJsonPath('employees.data.0.nama_lengkap', 'Budi Santoso');
    }

    public function test_can_filter_by_status_golongan_and_jenis_pegawai(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $pns = RefJenisPegawai::where('nama', 'PNS')->firstOrFail();
        $pppk = RefJenisPegawai::where('nama', 'PPPK')->firstOrFail();

        Employee::factory()->create([
            'nama_lengkap' => 'Target Filter',
            'status_aktif' => 'Pensiun',
            'golongan_terakhir' => 'III/a',
            'jenis_pegawai_id' => $pns->id,
        ]);
        Employee::factory()->create([
            'nama_lengkap' => 'Bukan Target',
            'status_aktif' => 'Pensiun',
            'golongan_terakhir' => 'IV/a',
            'jenis_pegawai_id' => $pppk->id,
        ]);

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT.'?status_aktif=Pensiun&golongan=III/a&jenis_pegawai_id='.$pns->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'employees.data');
        $response->assertJsonPath('employees.data.0.nama_lengkap', 'Target Filter');
        $response->assertJsonPath('employees.data.0.jenis_pegawai', 'PNS');
    }

    public function test_sorting_and_pagination_follow_allowed_parameters(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Charlie']);
        Employee::factory()->create(['nama_lengkap' => 'Bravo']);
        Employee::factory()->create(['nama_lengkap' => 'Alpha']);

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT.'?sort=nama_lengkap&direction=desc&per_page=10');

        $response->assertOk();
        $response->assertJsonPath('employees.data.0.nama_lengkap', 'Charlie');
        $response->assertJsonPath('employees.per_page', 10);
        $response->assertJsonPath('employees.total', 3);
    }

    public function test_invalid_per_page_is_rejected(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT.'?per_page=100');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('per_page');
    }

    public function test_permission_is_enforced_even_when_role_allows(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.read')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::PEGAWAI_ENDPOINT);

        $response->assertForbidden();
    }
}
