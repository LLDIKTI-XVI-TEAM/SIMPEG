<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\LeaveApprovalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Mengunci kebijakan alokasi pengajuan aktif tanpa mengubah saldo final.
 */
class LeaveBalanceReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /** @return array{user: User, employee: Employee, supervisor: Employee, pybmc: Employee} */
    private function makePemohon(): array
    {
        $jenisPegawai = RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $jenisPegawai->id]);
        $supervisor = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2024-01-01',
            'no_sk' => 'SK-RESERVASI-001',
            'tanggal_sk' => '2024-01-01',
        ]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);

        $chain = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Chain reservasi saldo',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Setup pengujian reservasi.',
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $supervisor->id,
                'is_final' => false,
            ],
            [
                'step_order' => 2,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $pybmc->id,
                'is_final' => true,
            ],
        ]);

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);

        return compact('user', 'employee', 'supervisor', 'pybmc');
    }

    private function annualLeaveType(): RefJenisCuti
    {
        return RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
    }

    private function sickLeaveType(): RefJenisCuti
    {
        return RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
    }

    /** @return array<string, string> */
    private function payload(RefJenisCuti $jenis, string $mulai = '2026-07-06', string $selesai = '2026-07-10'): array
    {
        return [
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => $mulai,
            'tanggal_selesai' => $selesai,
            'alasan' => 'Keperluan keluarga.',
            'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 1, Manado',
            'nomor_telepon' => '+62 (431) 123-456',
        ];
    }

    public function test_submit_reserves_active_annual_leave_and_blocks_combined_requests_above_balance(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis, '2026-07-06', '2026-07-14'))
            ->assertRedirect(route('cuti'));

        $firstRequest = LeaveRequest::query()->firstOrFail();
        $this->assertSame(7, $firstRequest->jumlah_hari_kerja);
        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $firstRequest->id,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 7,
            'tahun' => 2026,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'LEAVE_BALANCE_RESERVED',
            'auditable_type' => 'LeaveBalanceReservationEvent',
        ]);

        $balance = LeaveBalance::query()->where('employee_id', $aktor['employee']->id)->firstOrFail();
        $this->assertSame(12, $balance->sisa);
        $this->assertSame(0, $balance->terpakai);
        $this->assertSame(0, LeaveBalanceLedger::query()->count());

        $preview = app(LeaveBalanceService::class)->previewFor($aktor['employee'], Carbon::parse('2026-07-20'));
        $this->assertSame(12, $preview['saldo_aktual']);
        $this->assertSame(7, $preview['dialokasikan_aktif']);
        $this->assertSame(5, $preview['saldo_dapat_diajukan']);

        $this->actingAs($aktor['user'])
            ->postJson(route('cuti.store'), $this->payload($jenis, '2026-07-20', '2026-07-27'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tanggal_selesai']);

        $this->assertDatabaseCount('leave_requests', 1);
        $this->assertSame(7, (int) LeaveBalanceReservationEvent::query()->sum('amount'));
    }

    public function test_resubmit_recalculates_reservation_without_changing_final_balance(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $leaveRequest = LeaveRequest::query()->firstOrFail();
        app(LeaveApprovalService::class)->requestChanges($leaveRequest, $aktor['supervisor'], 'Tanggal perlu diperbaiki.');

        $this->actingAs($aktor['user'])
            ->patch(route('cuti.resubmit', $leaveRequest), [
                'tanggal_mulai' => '2026-07-13',
                'tanggal_selesai' => '2026-07-15',
                'alasan' => 'Tanggal telah disesuaikan.',
                'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 2, Manado',
                'nomor_telepon' => '+62 (431) 123-457',
            ])
            ->assertRedirect(route('cuti.show', $leaveRequest));

        $leaveRequest->refresh();
        $this->assertSame('menunggu_approval', $leaveRequest->status);
        $this->assertSame(3, $leaveRequest->jumlah_hari_kerja);
        $this->assertSame(3, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->sum('amount'));
        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $leaveRequest->id,
            'event_type' => LeaveBalanceReservationEvent::EVENT_ADJUSTED,
            'amount' => -2,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'LEAVE_BALANCE_RESERVATION_ADJUSTED',
            'auditable_type' => 'LeaveBalanceReservationEvent',
        ]);

        $balance = LeaveBalance::query()->where('employee_id', $aktor['employee']->id)->firstOrFail();
        $this->assertSame(12, $balance->sisa);
        $this->assertSame(0, $balance->terpakai);
    }

    public function test_resubmit_to_a_different_calendar_year_moves_reservation_atomically(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $leaveRequest = LeaveRequest::query()->firstOrFail();
        app(LeaveApprovalService::class)->requestChanges($leaveRequest, $aktor['supervisor'], 'Tanggal perlu dipindahkan ke tahun berikutnya.');

        $this->actingAs($aktor['user'])
            ->patch(route('cuti.resubmit', $leaveRequest), [
                'tanggal_mulai' => '2027-02-01',
                'tanggal_selesai' => '2027-02-03',
                'alasan' => 'Jadwal telah dipindahkan ke tahun berikutnya.',
                'alamat_selama_cuti' => 'Jl. Sam Ratulangi No. 3, Manado',
                'nomor_telepon' => '+62 (431) 123-458',
            ])
            ->assertRedirect(route('cuti.show', $leaveRequest));

        $leaveRequest->refresh();
        $this->assertSame('2027-02-01', $leaveRequest->tanggal_mulai->toDateString());
        $this->assertSame(3, $leaveRequest->jumlah_hari_kerja);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('tahun', 2026)
            ->sum('amount'));
        $this->assertSame(3, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('tahun', 2027)
            ->sum('amount'));
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $aktor['employee']->id,
            'tahun' => 2027,
            'sisa' => 12,
        ]);
        $this->assertSame(12, LeaveBalance::query()
            ->where('employee_id', $aktor['employee']->id)
            ->where('tahun', 2026)
            ->value('sisa'));
    }

    public function test_final_approval_converts_reservation_then_deducts_final_balance_once(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $leaveRequest = LeaveRequest::query()->firstOrFail();
        $approval = app(LeaveApprovalService::class);
        $approval->approve($leaveRequest, $aktor['supervisor']);
        $approval->approve($leaveRequest->fresh(), $aktor['pybmc']);

        $this->assertSame('disetujui', $leaveRequest->fresh()->status);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->sum('amount'));
        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $leaveRequest->id,
            'event_type' => LeaveBalanceReservationEvent::EVENT_CONVERTED,
            'amount' => -5,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'LEAVE_BALANCE_RESERVATION_CONVERTED',
            'auditable_type' => 'LeaveBalanceReservationEvent',
        ]);

        $balance = LeaveBalance::query()->where('employee_id', $aktor['employee']->id)->firstOrFail();
        $this->assertSame(7, $balance->sisa);
        $this->assertSame(5, $balance->terpakai);
        $this->assertSame(-5, (int) LeaveBalanceLedger::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED)
            ->sum('amount'));
    }

    public function test_not_approved_releases_reservation_without_deducting_final_balance(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $leaveRequest = LeaveRequest::query()->firstOrFail();
        app(LeaveApprovalService::class)->decline($leaveRequest, $aktor['supervisor'], 'Belum dapat disetujui.');

        $this->assertSame('tidak_disetujui', $leaveRequest->fresh()->status);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->sum('amount'));
        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $leaveRequest->id,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RELEASED,
            'amount' => -5,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'LEAVE_BALANCE_RESERVATION_RELEASED',
            'auditable_type' => 'LeaveBalanceReservationEvent',
        ]);

        $balance = LeaveBalance::query()->where('employee_id', $aktor['employee']->id)->firstOrFail();
        $this->assertSame(12, $balance->sisa);
        $this->assertSame(0, $balance->terpakai);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'leave_request_id' => $leaveRequest->id,
            'event_type' => LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED,
        ]);
    }

    public function test_postpone_and_change_request_keep_reservation_active(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->annualLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $leaveRequest = LeaveRequest::query()->firstOrFail();
        $approval = app(LeaveApprovalService::class);
        $approval->postpone($leaveRequest, $aktor['supervisor'], 'Menunggu penyesuaian tugas.');

        $this->assertSame('ditangguhkan', $leaveRequest->fresh()->status);
        $this->assertSame(5, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->sum('amount'));

        $approval->requestChanges($leaveRequest->fresh(), $aktor['supervisor'], 'Mohon perbaiki rincian pengajuan.');

        $this->assertSame('perlu_perubahan', $leaveRequest->fresh()->status);
        $this->assertSame(5, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->sum('amount'));
        $this->assertSame(1, LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('event_type', LeaveBalanceReservationEvent::EVENT_RESERVED)
            ->count());
    }

    public function test_non_annual_leave_never_creates_balance_reservation(): void
    {
        $aktor = $this->makePemohon();
        $jenis = $this->sickLeaveType();

        $this->actingAs($aktor['user'])
            ->post(route('cuti.store'), $this->payload($jenis))
            ->assertRedirect(route('cuti'));

        $this->assertDatabaseCount('leave_balance_reservation_events', 0);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'LEAVE_BALANCE_RESERVED']);
    }
}
