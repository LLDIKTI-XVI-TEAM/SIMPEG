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
            ->assertSee('<h1 class="text-2xl font-semibold text-ink">EWS Saya</h1>', false)
            ->assertSee('KGB', false)
            ->assertDontSee('Pensiun', false)
            ->assertDontSee('Ditangani', false)
            ->assertDontSee('Tidak Perlu', false);
    }

    public function test_personal_ews_only_accepts_page_sizes_available_in_the_interface(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        foreach ([10, 25, 50] as $perPage) {
            $response = $this->actingAs($user)->get(route('ews.saya', ['per_page' => $perPage]));

            $response->assertOk();
            $this->assertSame($perPage, $response->viewData('alerts')->perPage());
        }

        $emptyPageSize = $this->actingAs($user)->get(route('ews.saya').'?per_page=');

        $emptyPageSize->assertOk();
        $this->assertSame(10, $emptyPageSize->viewData('alerts')->perPage());

        $this->actingAs($user)
            ->from(route('ews.saya'))
            ->get(route('ews.saya', ['per_page' => 73]))
            ->assertRedirect(route('ews.saya'))
            ->assertSessionHasErrors('per_page');
    }

    public function test_withheld_promotion_alert_still_appears_on_personal_page(): void
    {
        // Pengingat pegawai yang belum memenuhi syarat memang ditahan, namun barisnya harus tetap
        // terlihat pada halaman pribadi supaya pegawai mengetahui status kelayakannya sendiri.
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Belum Layak',
            'is_kinerja_baik' => false,
        ]);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(90)->toDateString(),
            'interval_days' => 90,
            'is_processed' => false,
            'is_eligible' => false,
            'notified_at' => null,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('ews.saya'))
            ->assertOk()
            ->assertSee('Kenaikan Pangkat', false);
    }

    public function test_admin_cannot_open_personal_ews_page(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('ews.saya'))
            ->assertForbidden();
    }

    public function test_pegawai_without_linked_employee_gets_not_found(): void
    {
        $this->actingAsUnmapped(User::factory()->pegawai()->create(['employee_id' => null]))
            ->get(route('ews.saya'))
            ->assertRedirect(route('status-akun'));
    }
}
