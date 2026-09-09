<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeApiScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_pegawai_hanya_boleh_membaca_keluarga_dan_riwayat_milik_sendiri(): void
    {
        $ownEmployee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $pegawai = User::factory()->pegawai()->create(['employee_id' => $ownEmployee->id]);

        $this->actingAs($pegawai)
            ->getJson("/api/v1/pegawai/{$ownEmployee->id}/keluarga")
            ->assertOk();
        $this->actingAs($pegawai)
            ->getJson("/api/v1/pegawai/{$ownEmployee->id}/riwayat-kepangkatan")
            ->assertOk();

        $this->actingAs($pegawai)
            ->getJson("/api/v1/pegawai/{$otherEmployee->id}/keluarga")
            ->assertForbidden();
        $this->actingAs($pegawai)
            ->getJson("/api/v1/pegawai/{$otherEmployee->id}/riwayat-kepangkatan")
            ->assertForbidden();
    }

    public function test_super_admin_dan_admin_kepegawaian_tetap_boleh_membaca_data_pegawai_lain(): void
    {
        $target = Employee::factory()->create();

        foreach ([User::factory()->superAdmin()->create(), User::factory()->adminKepegawaian()->create()] as $manager) {
            $this->actingAs($manager)
                ->getJson("/api/v1/pegawai/{$target->id}/keluarga")
                ->assertOk();
            $this->actingAs($manager)
                ->getJson("/api/v1/pegawai/{$target->id}/riwayat-kepangkatan")
                ->assertOk();
        }
    }

    public function test_pimpinan_ditolak_pada_endpoint_keluarga_mentah_lintas_pegawai(): void
    {
        // Payload keluarga admin memuat NIK; Pimpinan wajib memakai surface
        // khusus yang dimasking, bukan endpoint API mentah lintas pegawai.
        $target = Employee::factory()->create();
        $pimpinan = User::factory()->pimpinan()->create(['employee_id' => Employee::factory()->create()->id]);

        $this->actingAs($pimpinan)
            ->getJson("/api/v1/pegawai/{$target->id}/keluarga")
            ->assertForbidden();
        $this->actingAs($pimpinan)
            ->getJson("/api/v1/pegawai/{$target->id}/riwayat-kepangkatan")
            ->assertForbidden();
    }

    public function test_grant_employee_read_pada_pegawai_tetap_hanya_mengizinkan_target_milik_sendiri(): void
    {
        $ownEmployee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $pegawai = User::factory()->pegawai()->create(['employee_id' => $ownEmployee->id]);
        $permission = Permission::query()->where('name', 'employees.read')->firstOrFail();
        Role::query()->where('name', 'pegawai')->firstOrFail()
            ->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($pegawai)
            ->getJson("/api/v1/pegawai/{$ownEmployee->id}/table-row")
            ->assertOk();
        $this->actingAs($pegawai)
            ->getJson("/api/v1/pegawai/{$otherEmployee->id}/table-row")
            ->assertForbidden();
    }

    public function test_grant_riwayat_pada_kabag_tetap_hanya_mengizinkan_bawahan_langsung(): void
    {
        $kabagEmployee = Employee::factory()->create();
        $directReport = Employee::factory()->create(['kepala_bagian_id' => $kabagEmployee->id]);
        $unrelatedEmployee = Employee::factory()->create();
        $kabag = User::factory()->kepalaBagian()->create(['employee_id' => $kabagEmployee->id]);
        $permission = Permission::query()->where('name', 'employee_histories.read')->firstOrFail();
        Role::query()->where('name', 'kepala_bagian')->firstOrFail()
            ->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($kabag)
            ->getJson("/api/v1/pegawai/{$directReport->id}/riwayat-kepangkatan")
            ->assertOk();
        $this->actingAs($kabag)
            ->getJson("/api/v1/pegawai/{$unrelatedEmployee->id}/riwayat-kepangkatan")
            ->assertForbidden();
    }
}
