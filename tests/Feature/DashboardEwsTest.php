<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardEwsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_pegawai_dashboard_ews_uses_real_own_alerts(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Dashboard EWS']);
        $otherEmployee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Dashboard Lain']);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(14)->toDateString(),
            'interval_days' => 14,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        EwsAlert::create([
            'employee_id' => $otherEmployee->id,
            'type' => 'PENSIUN',
            'target_date' => now()->addDays(60)->toDateString(),
            'interval_days' => 60,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Pegawai Dashboard EWS', false)
            ->assertDontSee('Pegawai Dashboard Lain', false)
            ->assertSee('EWS Saya', false)
            ->assertSee(route('ews.saya'), false)
            ->assertDontSee('Budi Santoso', false);
    }
}
