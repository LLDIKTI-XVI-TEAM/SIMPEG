<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PimpinanLeaveDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_pimpinan_approval_uses_the_leave_workflow_and_records_the_decision(): void
    {
        $pemohon = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $pimpinan = User::factory()->pimpinan()->create(['employee_id' => $approver->id]);
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'cuti_sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        $this->actingAs($pimpinan)
            ->post(route('pimpinan.cuti.decision', $leave), [
                'keputusan' => 'DISETUJUI',
                'catatan' => 'Disetujui.',
            ])
            ->assertRedirect(route('pimpinan.cuti.show', $leave))
            ->assertSessionHas('success', 'Pengajuan cuti berhasil disetujui.');

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leave->id,
            'status' => 'disetujui',
        ]);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leave->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'APPROVE',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'APPROVE',
            'auditable_type' => 'LeaveRequest',
            'auditable_id' => $leave->id,
        ]);
    }

    public function test_leave_detail_uses_the_actual_request_and_active_approval_step(): void
    {
        $pemohon = Employee::factory()->create(['nama_lengkap' => 'Pegawai Cuti Aktual']);
        $approver = Employee::factory()->create();
        $pimpinan = User::factory()->pimpinan()->create(['employee_id' => $approver->id]);
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'cuti_sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        LeaveBalance::create([
            'employee_id' => $pemohon->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 3,
            'sisa' => 9,
        ]);

        $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.show', $leave))
            ->assertOk()
            ->assertSee('Pegawai Cuti Aktual')
            ->assertSee('Cuti Sakit')
            ->assertSee('9 Hari')
            ->assertSee(route('pimpinan.cuti.decision', $leave), false);
    }

    public function test_leave_index_lists_real_requests_and_links_to_the_request_detail(): void
    {
        $pimpinan = User::factory()->pimpinan()->create();
        $pemohon = Employee::factory()->create(['nama_lengkap' => 'Pegawai Antrean Cuti']);
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'cuti_sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);

        $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.index'))
            ->assertOk()
            ->assertSee($pemohon->nama_lengkap)
            ->assertSee($jenisCuti->nama)
            ->assertSee($leave->id)
            ->assertSee('aria-label="Detail pengajuan cuti Pegawai Antrean Cuti"', false);
    }
}
