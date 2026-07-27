<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Memastikan kartu PYBMC Global memakai pencarian pegawai mandiri sehingga
 * dapat diisi pada lingkungan baru tanpa bergantung pada pencarian kandidat
 * di panel chain pegawai.
 */
class CutiConfigPybmcGlobalLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_kartu_pybmc_global_memakai_combobox_pencarian_mandiri(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $response = $this->actingAs($actor)->get(route('cuti.config'));

        $response->assertOk()
            ->assertSee('id="pybmc-global-lookup"', false)
            ->assertSee('name="approver_employee_id"', false)
            // Endpoint lookup dirender di dalam @js sehingga slash ter-escape; cukup kunci nama state-nya.
            ->assertSee('pybmcLookupEndpoint', false)
            // Select statis lama yang kosong pada lingkungan baru sudah tidak dipakai.
            ->assertDontSee('id="pybmc-global-approver"', false);
    }

    public function test_pybmc_global_aktif_terisi_kembali_pada_combobox(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $approver = Employee::factory()->create(['nama_lengkap' => 'Pejabat PYBMC Aktif']);
        LeavePybmcGlobalConfig::create([
            'approver_employee_id' => $approver->id,
            'effective_from' => today(),
            'created_by' => $actor->id,
            'change_reason' => 'Penetapan awal PYBMC global untuk pengujian.',
        ]);

        $response = $this->actingAs($actor)->get(route('cuti.config'));

        $response->assertOk()
            ->assertSee('Pejabat PYBMC Aktif')
            ->assertSee($approver->id, false);
    }
}
