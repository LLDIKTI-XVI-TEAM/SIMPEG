<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
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
            ->assertSee('Daftar Peringatan Dini (EWS)', false)
            ->assertDontSee('Pegawai Dashboard Lain', false)
            ->assertSee('EWS Saya', false)
            ->assertSee(route('ews.saya'), false)
            ->assertDontSee('Budi Santoso', false);
    }

    public function test_admin_dashboard_complete_k3_payload_renders_metrics_and_valid_zero_states(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create(['name' => 'Admin Dashboard']));

        $html = view('admin.dashboard', $this->completeK3Payload())->render();

        $this->assertStringContainsString('data-k3-metric="pegawai-aktif"', $html);
        $this->assertStringContainsString('data-k3-metric="kenaikan-pangkat"', $html);
        $this->assertStringContainsString('data-k3-metric="cuti-menunggu"', $html);
        $this->assertStringContainsString('1.250', $html);
        $this->assertStringContainsString('0 sepanjang tahun ini', $html);
        $this->assertStringContainsString('Tidak ada kenaikan pangkat pada bulan ini.', $html);
        $this->assertStringContainsString('Belum ada aktivitas audit terbaru.', $html);
        $this->assertStringContainsString("dashboardChart({ type: 'doughnut'", $html);
        $this->assertStringContainsString("dashboardChart({ type: 'line'", $html);
        $this->assertStringNotContainsString('Data belum tersedia.', $html);
    }

    public function test_admin_dashboard_missing_k3_payload_uses_individual_empty_states_without_global_notice(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create(['name' => 'Admin Dashboard']));

        $html = view('admin.dashboard', [
            'dashboardEwsAlerts' => [],
            'dashboardEwsTotal' => 0,
            'dashboardEwsUrgent' => 0,
            'dashboardEwsWarning' => 0,
            'dashboardEwsInfo' => 0,
            'dashboardEwsLink' => route('ews'),
        ])->render();

        $this->assertStringNotContainsString('Ringkasan operasional sedang disiapkan.', $html);
        $this->assertStringNotContainsString('Sebagian ringkasan operasional belum tersedia.', $html);
        $this->assertStringContainsString('Data belum tersedia.', $html);
    }

    public function test_admin_dashboard_keeps_unclassified_ranks_out_of_chart_and_explains_them(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create(['name' => 'Admin Dashboard']));

        $payload = $this->completeK3Payload();
        $payload['distribusiGolongan']['Belum Diisi'] = 2;
        $payload['distribusiGolongan']['IX'] = 1;

        $html = view('admin.dashboard', $payload)->render();

        $this->assertStringContainsString('1.250 pegawai bergolongan', $html);
        $this->assertStringContainsString(
            '3 pegawai tidak ditampilkan pada grafik karena golongannya belum diisi atau memakai nilai legacy (belum diisi, IX).',
            $html,
        );
    }

    public function test_admin_rank_chart_uses_all_active_employees_for_tooltip_percentage(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create(['name' => 'Admin Dashboard']));

        $payload = $this->completeK3Payload();
        $payload['totalPegawaiAktif'] = 2;
        $payload['distribusiGolongan'] = [
            'III/a' => 1,
            'Belum Diisi' => 1,
        ];

        $html = view('admin.dashboard', $payload)->render();

        // Golongan standar bernilai nol tetap dirender; satu pegawai III/a berada pada urutan kesembilan.
        $expectedRankData = [0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0];
        $this->assertStringContainsString('data: '.Js::from($expectedRankData).', tooltipTotal: 2', $html);
    }

    public function test_admin_dashboard_keeps_chart_tone_paired_to_filtered_composition_row(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create(['name' => 'Admin Dashboard']));

        $payload = $this->completeK3Payload();
        $payload['totalPegawaiAktif'] = 4;
        $payload['komposisiPegawai'] = [
            'PNS' => 0,
            'PPPK' => 4,
            'CPNS' => 0,
        ];

        $html = view('admin.dashboard', $payload)->render();

        $this->assertStringContainsString(
            'labels: '.Js::from(['PPPK']).', data: '.Js::from([4]).', tones: '.Js::from(['secondary']),
            $html,
        );
        $this->assertStringContainsString('bg-secondary', $html);
    }

    /**
     * Kontrak render frontend K-3. BuildAdminDashboardAction nantinya harus
     * mengirim seluruh key ini, dengan angka nol dan array kosong sebagai data valid.
     *
     * @return array<string, mixed>
     */
    private function completeK3Payload(): array
    {
        return [
            'dashboardEwsAlerts' => [],
            'dashboardEwsTotal' => 0,
            'dashboardEwsUrgent' => 0,
            'dashboardEwsWarning' => 0,
            'dashboardEwsInfo' => 0,
            'dashboardEwsLink' => route('ews'),
            'totalPegawaiAktif' => 1250,
            'komposisiPegawai' => [
                'PNS' => 900,
                'PPPK' => 300,
                'CPNS' => 50,
            ],
            'kenaikanPangkatBulanIni' => 0,
            'kenaikanPangkatTahunIni' => 0,
            'daftarKenaikanPangkat' => [],
            'cutiMenunggu' => 0,
            'cutiDisetujuiBulanIni' => 0,
            'cutiDitangguhkan' => 0,
            'distribusiGolongan' => [
                'III/a' => 700,
                'IV/a' => 550,
            ],
            'auditTerbaru' => [],
            'trenPegawai' => [
                ['label' => 'Jan 2026', 'jumlah' => 1240],
                ['label' => 'Feb 2026', 'jumlah' => 1250],
            ],
        ];
    }
}
