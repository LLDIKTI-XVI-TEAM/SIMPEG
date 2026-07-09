<?php

namespace Tests\Feature;

use App\Models\DisciplineRecord;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EwsActivePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_allowed_roles_can_open_ews_active_page(): void
    {
        foreach (['super_admin', 'admin_kepegawaian', 'pimpinan'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get(route('ews'))
                ->assertOk()
                ->assertSee('EWS', false);
        }
    }

    public function test_pegawai_cannot_open_ews_active_page(): void
    {
        $this->actingAs(User::factory()->pegawai()->create())
            ->get(route('ews'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_ews_active_page(): void
    {
        $this->get(route('ews'))->assertRedirect(route('login'));
    }

    public function test_alerts_are_sorted_by_remaining_days(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $late = $this->alert(now()->addDays(90)->toDateString(), 'KGB', 'Pegawai KGB 90 Hari');
        $soon = $this->alert(now()->addDays(14)->toDateString(), 'KGB', 'Pegawai KGB 14 Hari');

        $response = $this->actingAs($user)->get(route('ews'));

        $response->assertOk();
        $response->assertSeeInOrder([
            $soon->employee->nama_lengkap,
            $late->employee->nama_lengkap,
        ]);
    }

    public function test_event_filter_only_shows_selected_event(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $kgb = $this->alert(now()->addDays(60)->toDateString(), 'KGB', 'Pegawai KGB Filter');
        $pensiun = $this->alert(now()->addDays(90)->toDateString(), 'PENSIUN', 'Pegawai Pensiun Filter');

        $response = $this->actingAs($user)->get(route('ews', ['event' => 'KGB']));

        $response->assertOk();
        $response->assertSee($kgb->employee->nama_lengkap);
        $response->assertDontSee($pensiun->employee->nama_lengkap);
    }

    public function test_satyalancana_event_filter_is_available(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $satyalancana = $this->alert(now()->addDays(90)->toDateString(), 'SATYALANCANA', 'Pegawai Satyalancana Filter');
        $kgb = $this->alert(now()->addDays(60)->toDateString(), 'KGB', 'Pegawai KGB Lain');

        $response = $this->actingAs($user)->get(route('ews', ['event' => 'Satyalancana']));

        $response->assertOk();
        $response->assertSee('Satyalancana');
        $response->assertSee($satyalancana->employee->nama_lengkap);
        $response->assertDontSee($kgb->employee->nama_lengkap);
    }

    public function test_status_filter_only_shows_selected_followup_status(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $active = $this->alert(now()->addDays(60)->toDateString(), 'KGB', 'Pegawai Status Aktif');
        $handled = $this->alert(now()->addDays(60)->toDateString(), 'KGB', 'Pegawai Status Ditangani');
        $handled->update([
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_at' => now(),
            'handled_by' => $user->id,
            'handled_note' => 'Berkas sudah selesai diproses.',
            'is_processed' => true,
        ]);

        $response = $this->actingAs($user)->get(route('ews', ['status' => EwsAlert::FOLLOWUP_STATUS_HANDLED]));

        $response->assertOk();
        $response->assertSee($handled->employee->nama_lengkap);
        $response->assertSee('Berkas sudah selesai diproses.');
        $response->assertDontSee($active->employee->nama_lengkap);
    }

    public function test_admin_can_see_followup_action_for_active_alert(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $alert = $this->alert(now()->addDays(60)->toDateString(), 'KGB', 'Pegawai Followup Button');

        $this->actingAs($user)
            ->get(route('ews'))
            ->assertOk()
            ->assertSee($alert->id, false)
            ->assertSee('Catatan Tindak Lanjut EWS');
    }

    public function test_non_eligible_promotion_alert_still_appears_for_admin(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Kinerja Buruk',
            'is_kinerja_baik' => false,
        ]);

        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(90)->toDateString(),
            'interval_days' => 90,
            'is_processed' => false,
        ]);

        $this->actingAs($user)
            ->get(route('ews'))
            ->assertOk()
            ->assertSee('Pegawai Kinerja Buruk')
            ->assertSee('Tidak Eligible')
            ->assertSee('Kinerja perlu ditinjau');
    }

    public function test_active_discipline_makes_promotion_alert_non_eligible(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Disiplin Aktif',
            'is_kinerja_baik' => true,
        ]);

        DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Sedang',
            'deskripsi' => 'Pelanggaran disiplin aktif',
            'tanggal_mulai' => now()->subDay()->toDateString(),
            'no_sk' => 'SK-DIS-001',
            'tanggal_sk' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);
        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(90)->toDateString(),
            'interval_days' => 90,
            'is_processed' => false,
        ]);

        $this->actingAs($user)
            ->get(route('ews'))
            ->assertOk()
            ->assertSee('Pegawai Disiplin Aktif')
            ->assertSee('Tidak Eligible')
            ->assertSee('Hukuman disiplin aktif');
    }

    public function test_employee_name_links_to_employee_detail(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $alert = $this->alert(now()->addDays(60)->toDateString(), 'KGB', 'Pegawai Link Detail');

        $this->actingAs($user)
            ->get(route('ews'))
            ->assertOk()
            ->assertSee(route('pegawai.show', $alert->employee_id), false);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function alert(string $targetDate, string $type, string $name): EwsAlert
    {
        $employee = Employee::factory()->create(['nama_lengkap' => $name]);

        return EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'target_date' => $targetDate,
            'interval_days' => match ($type) {
                'KGB' => 60,
                'PENSIUN' => 90,
                'SATYALANCANA' => 90,
                default => 90,
            },
            'is_processed' => false,
        ]);
    }
}
