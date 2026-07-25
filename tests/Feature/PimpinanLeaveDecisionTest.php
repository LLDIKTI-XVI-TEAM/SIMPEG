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

    public function test_pimpinan_decline_uses_the_final_not_approved_contract(): void
    {
        $pemohon = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $pimpinan = User::factory()->pimpinan()->create(['employee_id' => $approver->id]);
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'cuti_sakit_pimpinan_decline',
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
                'keputusan' => 'TIDAK_DISETUJUI',
                'catatan' => 'Dokumen pendukung tidak sesuai.',
            ])
            ->assertRedirect(route('pimpinan.cuti.show', $leave));

        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'status' => 'tidak_disetujui']);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'status' => 'tidak_disetujui',
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leave->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'NOT_APPROVED',
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
        $approver = Employee::factory()->create(['nama_lengkap' => 'Pejabat Berbeda Dari Label']);
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Sakit Kontrak Daftar Pimpinan',
            'code' => 'cuti_sakit_kontrak_daftar_pimpinan',
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
            'step_type' => 'verifikator_kontrak_pimpinan',
            'role_label' => 'Verifikator Kontrak Pimpinan',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => false,
        ]);

        $response = $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.index'));
        $row = $response->viewData('leaves')->getCollection()->firstWhere('id', $leave->id);

        $response
            ->assertOk()
            ->assertSee($pemohon->nama_lengkap)
            ->assertSee($jenisCuti->nama)
            ->assertSee($leave->id)
            ->assertSee('aria-label="Detail pengajuan cuti Pegawai Antrean Cuti"', false);
        $this->assertSame(1, $response->viewData('leaves')->total());
        $this->assertArrayHasKey('current_step_label', $row->getAttributes());
        $this->assertSame('Verifikator Kontrak Pimpinan', $row->getAttribute('current_step_label'));
        $this->assertArrayNotHasKey('activeStep', $row->getAttributes());
    }

    public function test_leave_index_renders_tahap_aktif_role_label_and_dash_for_terminal_request(): void
    {
        $pimpinan = User::factory()->pimpinan()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Kontrak Kolom Tahap Pimpinan',
            'code' => 'cuti_kontrak_kolom_tahap_pimpinan',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $activeLeave = LeaveRequest::create([
            'employee_id' => Employee::factory()->create(['nama_lengkap' => 'Pegawai Antrean Berjalan Pimpinan'])->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-09-07',
            'tanggal_selesai' => '2026-09-08',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Uji label snapshot Pimpinan.',
            'status' => 'menunggu_approval',
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $activeLeave->id,
            'step_order' => 1,
            'step_type' => 'pybmc_kontrak_tampilan',
            'role_label' => 'PYBMC Kontrak Tampilan',
            'approver_employee_id' => Employee::factory()->create(['nama_lengkap' => 'Nama Pejabat Bukan Label'])->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        $terminalLeave = LeaveRequest::create([
            'employee_id' => Employee::factory()->create(['nama_lengkap' => 'Pegawai Tahap Terminal Pimpinan'])->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-09-09',
            'tanggal_selesai' => '2026-09-09',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji tahap terminal Pimpinan.',
            'status' => 'disetujui',
        ]);

        $response = $this->actingAs($pimpinan)->get(route('pimpinan.cuti.index'));

        $response->assertOk()
            ->assertSee('PYBMC Kontrak Tampilan');
        $this->assertSame(2, $response->viewData('leaves')->total());
        $this->assertMatchesRegularExpression(
            '/Pegawai Tahap Terminal Pimpinan.*?<span class="text-muted">-<\/span>/s',
            $response->getContent(),
        );
    }

    public function test_terminal_leave_index_row_exposes_a_null_current_step_label_without_active_step_alias(): void
    {
        $pimpinan = User::factory()->pimpinan()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Kontrak Terminal Pimpinan',
            'code' => 'cuti_kontrak_terminal_pimpinan',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => Employee::factory()->create(['nama_lengkap' => 'Pegawai Terminal Kontrak Pimpinan'])->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-09-10',
            'tanggal_selesai' => '2026-09-10',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji kontrak terminal daftar Pimpinan.',
            'status' => 'disetujui',
        ]);

        $response = $this->actingAs($pimpinan)->get(route('pimpinan.cuti.index'));
        $row = $response->viewData('leaves')->getCollection()->firstWhere('id', $leave->id);

        $response->assertOk();
        $this->assertSame(1, $response->viewData('leaves')->total());
        $this->assertArrayHasKey('current_step_label', $row->getAttributes());
        $this->assertNull($row->getAttribute('current_step_label'));
        $this->assertArrayNotHasKey('activeStep', $row->getAttributes());
    }

    public function test_leave_index_has_a_tahap_aktif_column(): void
    {
        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.cuti.index'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<th\b[^>]*>\s*Tahap Aktif\s*<\/th>/s',
            $response->getContent(),
        );
    }
}
