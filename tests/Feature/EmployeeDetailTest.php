<?php

namespace Tests\Feature;

use App\Models\DisciplineRecord;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\SalaryHistory;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_admin_can_view_employee_detail_with_tab_relations_ordered_newest_first_without_sensitive_fields(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Detail Pegawai']);
        $golongan = RefGolongan::where('kode', 'III/a')->firstOrFail();
        $jenisJabatan = RefJenisJabatan::firstOrFail();
        $eselon = RefEselon::firstOrFail();
        $unitKerja = RefUnitKerja::firstOrFail();

        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2024-01-01',
            'no_sk' => 'SK-RANK-OLD',
            'tanggal_sk' => '2024-01-10',
            'is_latest' => false,
        ]);
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2026-01-01',
            'no_sk' => 'SK-RANK-NEW',
            'tanggal_sk' => '2026-01-10',
            'is_latest' => true,
        ]);
        PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Analis SDM',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'eselon_id' => $eselon->id,
            'unit_kerja_id' => $unitKerja->id,
            'tmt_jabatan' => '2026-02-01',
            'no_sk' => 'SK-JAB-NEW',
            'tanggal_sk' => '2026-02-10',
            'is_latest' => true,
        ]);
        SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => '2026-03-01',
            'gaji_pokok' => 4500000,
            'no_sk' => 'SK-KGB-NEW',
            'tanggal_sk' => '2026-03-10',
            'is_latest' => true,
        ]);
        DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'Teguran tertulis',
            'tanggal_mulai' => '2026-04-01',
            'no_sk' => 'SK-DIS-NEW',
            'tanggal_sk' => '2026-04-10',
            'is_active' => true,
        ]);

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertOk();
        $response->assertJsonPath('message', 'Detail pegawai berhasil diambil.');
        $response->assertJsonPath('employee.nama_lengkap', 'Detail Pegawai');
        $response->assertJsonPath('employee.jenis_pegawai.id', $employee->jenis_pegawai_id);
        $response->assertJsonPath('employee.rank_histories.0.no_sk', 'SK-RANK-NEW');
        $response->assertJsonPath('employee.rank_histories.0.golongan.kode', 'III/a');
        $response->assertJsonPath('employee.position_histories.0.jenis_jabatan.id', $jenisJabatan->id);
        $response->assertJsonPath('employee.position_histories.0.eselon.id', $eselon->id);
        $response->assertJsonPath('employee.position_histories.0.unit_kerja.id', $unitKerja->id);
        $response->assertJsonPath('employee.salary_histories.0.no_sk', 'SK-KGB-NEW');
        $response->assertJsonPath('employee.discipline_records.0.no_sk', 'SK-DIS-NEW');
        $response->assertJsonMissingPath('employee.nik');
        $response->assertJsonMissingPath('employee.no_kk');
        $response->assertJsonMissingPath('employee.keycloak_id');
        $response->assertJsonMissingPath('employee.role');
    }

    public function test_discipline_records_are_loaded_in_general_detail_and_dedicated_endpoint(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'Teguran tertulis',
            'tanggal_mulai' => '2026-04-01',
            'no_sk' => 'SK-DIS-CONTRACT',
            'tanggal_sk' => '2026-04-10',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->getJson("/api/v1/pegawai/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('employee.discipline_records.0.no_sk', 'SK-DIS-CONTRACT');

        $this->getJson("/api/v1/pegawai/{$employee->id}/disiplin")
            ->assertOk()
            ->assertJsonPath('records.0.no_sk', 'SK-DIS-CONTRACT');
    }

    public function test_pegawai_cannot_view_employee_detail(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertForbidden();
    }

    public function test_admin_without_employee_read_permission_cannot_view_detail(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.read')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertForbidden();
    }
}
