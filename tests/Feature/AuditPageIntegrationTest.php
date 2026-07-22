<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditPageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_halaman_audit_memetakan_data_dari_audit_logs_ke_kontrak_ui(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $audit = AuditLog::create([
            'user_id' => $admin->id,
            'user_name' => 'Admin Audit Nyata',
            'event' => 'UPDATE',
            'auditable_type' => 'EwsConfig',
            'auditable_id' => null,
            'old_values' => ['key' => 'ews_scheduler_time', 'value' => '07:00'],
            'new_values' => ['key' => 'ews_scheduler_time', 'value' => '08:30'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get('/dashboard/audit');

        $response->assertOk();
        $response->assertViewHas('auditLogs', function (array $logs) use ($audit): bool {
            $log = collect($logs)->firstWhere('id', $audit->id);

            return $log !== null
                && $log['operator'] === 'Admin Audit Nyata'
                && $log['modul'] === 'EwsConfig'
                && $log['record_id'] === 'ews_scheduler_time'
                && $log['kategori'] === 'konfigurasi_sistem'
                && $log['new_values']['value'] === '08:30';
        });

        $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get('/dashboard/audit/'.$audit->id)
            ->assertOk()
            ->assertSee('Admin Audit Nyata')
            ->assertSee('08:30');
    }

    public function test_perubahan_konfigurasi_ews_muncul_di_halaman_audit(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->post('/konfigurasi/update', [
                'ews_scheduler_time' => '08:30',
                'pangkat_required_years' => '4',
                'pangkat_h90' => '95',
                'pangkat_h60' => '65',
                'pangkat_h30' => '35',
                'kgb_required_years' => '2',
                'kgb_h60' => '60',
                'kgb_h30' => '30',
                'kgb_h14' => '14',
                'pensiun_required_age_years' => '0',
                'pensiun_y1' => '365',
                'pensiun_m6' => '180',
                'pensiun_m3' => '90',
                'pppk_contract_years' => '5',
                'pppk_m6' => '180',
                'pppk_m3' => '90',
                'pppk_m1' => '30',
                'satyalancana_years_1' => '10',
                'satyalancana_years_2' => '20',
                'satyalancana_years_3' => '30',
                'satyalancana_h180' => '180',
                'satyalancana_h90' => '90',
                'satyalancana_h30' => '30',
                'reason' => 'Verifikasi integrasi halaman audit',
            ])
            ->assertRedirect('/konfigurasi');

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get('/dashboard/audit');

        $response->assertOk();
        $response->assertViewHas('auditLogs', function (array $logs): bool {
            return collect($logs)->contains(function (array $log): bool {
                return $log['modul'] === 'EwsConfig'
                    && $log['record_id'] === 'ews_scheduler_time'
                    && data_get($log, 'old_values.value') === '07:00'
                    && data_get($log, 'new_values.value') === '08:30'
                    && data_get($log, 'new_values.reason') === 'Verifikasi integrasi halaman audit';
            });
        });
    }
}
