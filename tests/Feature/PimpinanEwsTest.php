<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PimpinanEwsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_pimpinan_ews_uses_active_alert_data_and_real_event_filtering(): void
    {
        $pppkEmployee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Kontrak Aktual']);
        $otherEmployee = Employee::factory()->create(['nama_lengkap' => 'Pegawai KGB Lain']);
        EwsAlert::create([
            'employee_id' => $pppkEmployee->id,
            'type' => 'KONTRAK_PPPK',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        EwsAlert::create([
            'employee_id' => $otherEmployee->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(60)->toDateString(),
            'interval_days' => 60,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.ews.index', ['event' => 'Kontrak PPPK']))
            ->assertOk()
            ->assertSee('Pegawai Kontrak Aktual')
            ->assertDontSee('Pegawai KGB Lain')
            ->assertSee('Tanggal Target')
            ->assertSee('Status Kelayakan')
            ->assertSee('Layak')
            ->assertSee('Aktif')
            ->assertDontSee('href="#"', false)
            ->assertDontSee('data per halaman')
            // Kode: pimpinan/ews/index.blade.php:61 menautkan ke rbac.pegawai.show.
            ->assertSee(route('rbac.pegawai.show', $pppkEmployee), false);
    }
}
