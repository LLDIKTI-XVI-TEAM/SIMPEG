<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeImportMappingUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_admin_kepegawaian_can_access_import_data_page_with_complete_mapping_ui(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($admin)->get('/pegawai/import-data');

        $response->assertOk();
        $response->assertSee('Import Data Pegawai');
        $response->assertSee('Pemetaan Kolom');
        $response->assertSee('Kolom tidak dikenal ditemukan');
        $response->assertSee('Field wajib belum dipetakan');
        $response->assertSee('simpegTargetFields:', false);
        $response->assertSee('await this.persistMapping();', false);
        $response->assertSee('aria-describedby="mapping-readiness-message"', false);
        $response->assertSee('isLockedIgnoredHeader(header)', false);
        $response->assertSee('value="tidak_dipakai"', false);
    }
}
