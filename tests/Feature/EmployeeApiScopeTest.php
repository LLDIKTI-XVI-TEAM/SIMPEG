<?php

namespace Tests\Feature;

use App\Models\Employee;
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
}
