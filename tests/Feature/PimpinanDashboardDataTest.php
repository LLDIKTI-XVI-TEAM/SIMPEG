<?php

namespace Tests\Feature;

use App\Actions\Dashboards\BuildPimpinanDashboardAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PimpinanDashboardDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_pimpinan_dashboard_renders_current_database_records_and_real_monitoring_links(): void
    {
        $this->seed(RbacSeeder::class);

        $pimpinanEmployee = Employee::factory()->create(['nama_lengkap' => 'Pimpinan LLDIKTI']);
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Eka Pramesti',
            'nip' => '198505052011052005',
            'golongan_terakhir' => 'III/d',
        ]);
        $rank = RefGolongan::create(['kode' => 'III/d', 'nama' => 'Penata Tingkat I']);
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $rank->id,
            'tmt_pangkat' => now()->startOfMonth(),
            'no_sk' => 'SK-321/2026',
            'tanggal_sk' => now()->startOfMonth(),
            'is_latest' => true,
        ]);
        $leaveType = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'CUTI_TAHUNAN',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => now()->addWeek(),
            'tanggal_selesai' => now()->addWeeks(2),
            'jumlah_hari_kerja' => 5,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $pimpinanEmployee->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(14),
            'interval_days' => 30,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        AuditLog::create([
            'user_name' => 'Admin Kepegawaian',
            'event' => 'UPDATE',
            'auditable_type' => Employee::class,
            'auditable_id' => $employee->id,
            'new_values' => ['nama_lengkap' => $employee->nama_lengkap],
        ]);

        $response = $this->actingAs(User::factory()->pimpinan()->create([
            'employee_id' => $pimpinanEmployee->id,
        ]))->get(route('pimpinan.dashboard'));

        $response->assertOk()
            ->assertSee('Eka Pramesti')
            ->assertSee('SK-321/2026')
            ->assertSee('Admin Kepegawaian')
            ->assertSee(route('pimpinan.cuti.show', $leave), false)
            ->assertSee(route('pimpinan.ews.index', ['event' => 'Kenaikan Pangkat']), false)
            ->assertSee(route('pimpinan.cuti.index', ['status' => 'menunggu']), false)
            ->assertSee('Pengajuan Cuti')
            ->assertSee('href="'.route('cuti').'"', false)
            ->assertSee('globalSearch()', false)
            ->assertDontSee('Ahmad Fauzi')
            ->assertDontSee('Nadia Kusuma')
            ->assertDontSee('Admin HR');
    }

    public function test_tren_pegawai_aktif_excludes_non_aktif_and_mutasi_employees(): void
    {
        $this->seed(RbacSeeder::class);
        $pimpinanUser = User::factory()->pimpinan()->create();

        // Pegawai Non-Aktif tanpa tanggal pensiun
        Employee::factory()->create([
            'status_aktif' => 'Non-Aktif',
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(6),
        ]);

        // Pegawai Mutasi tanpa tanggal pensiun
        Employee::factory()->create([
            'status_aktif' => 'Mutasi',
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(6),
        ]);

        // Pegawai Aktif
        $aktif = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => null,
            'created_at' => now()->subMonths(6),
        ]);

        $action = app(BuildPimpinanDashboardAction::class);
        $data = $action->execute($pimpinanUser);

        $lastTrendPoint = collect($data['trenPegawai'])->last();
        $this->assertEquals(1, $lastTrendPoint['jumlah']);
    }
}
