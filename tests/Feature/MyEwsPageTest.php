<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyEwsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_pegawai_can_open_personal_ews_and_only_see_own_alerts(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai EWS Saya']);
        $otherEmployee = Employee::factory()->create(['nama_lengkap' => 'Pegawai EWS Lain']);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        EwsAlert::create([
            'employee_id' => $otherEmployee->id,
            'type' => 'PENSIUN',
            'target_date' => now()->addDays(90)->toDateString(),
            'interval_days' => 90,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('ews.saya'))
            ->assertOk()
            ->assertSee('EWS Saya', false)
            ->assertSee('KGB', false)
            ->assertDontSee('Pensiun', false)
            ->assertDontSee('Ditangani', false)
            ->assertDontSee('Tidak Perlu', false);
    }

    public function test_admin_cannot_open_personal_ews_page(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('ews.saya'))
            ->assertForbidden();
    }

    public function test_pegawai_without_linked_employee_gets_not_found(): void
    {
        $this->actingAs(User::factory()->pegawai()->create(['employee_id' => null]))
            ->get(route('ews.saya'))
            ->assertNotFound();
    }
}
