<?php

namespace Tests\Feature;

use App\Actions\Cuti\ListPendingLeaveApprovalsAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression test untuk rollover saldo cuti tahunan berbasis ledger.
 * Fokusnya menjaga idempotensi CLI/scheduler, cap 12/18/24, dan pemisahan penangguhan dinas dari status Ditangguhkan biasa.
 */
class LeaveBalanceRolloverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        Carbon::setTestNow('2027-01-01 00:05:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rollover_normal_membawa_maksimal_enam_hari_ke_tahun_target(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 10,
            'sisa' => 10,
        ]));

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(6, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(18, $balance->sisa);
        $this->assertSame(4, $balance->hangus);

        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'rollover_applied',
            'amount' => 0,
            'source_year' => 2026,
            'dedup_key' => "{$employee->id}:2027:rollover_applied",
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'carry_over_granted',
            'amount' => 6,
            'source_year' => 2026,
            'dedup_key' => "{$employee->id}:2027:carry_over_granted:2026",
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'carry_over_expired',
            'amount' => 0,
            'source_year' => 2026,
            'dedup_key' => "{$employee->id}:2027:carry_over_expired:2026",
        ]);

        $audit = AuditLog::where('event', 'LEAVE_ROLLOVER_APPLIED')->firstOrFail();
        $this->assertSame('LeaveBalance', $audit->auditable_type);
        $this->assertSame($employee->id, $audit->new_values['employee_id']);
        $this->assertSame(2027, $audit->new_values['tahun']);
        $this->assertSame(2026, $audit->new_values['tahun_sumber']);
        $this->assertSame(10, $audit->old_values['old_balance']);
        $this->assertSame(18, $audit->new_values['new_balance']);
        $this->assertSame(8, $audit->new_values['delta']);
    }

    #[DataProvider('activeAnnualLeaveStatusProvider')]
    public function test_rollover_returns_active_annual_requests_and_releases_reservation_once(string $status): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create(['employee_id' => $employee->id]);
        $approver = Employee::factory()->create();
        $sourceBalance = LeaveBalance::create($this->balancePayload($employee, 2026));
        $request = $this->activeAnnualLeaveRequest($employee, 2026, 3, '2026-12-21');
        $request->forceFill(['status' => $status])->save();
        $request->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        $stepId = $request->steps()->value('id');
        $this->reserveRequest($request, $sourceBalance, 3, $actor);

        $service = app(LeaveBalanceService::class);
        $service->rolloverYear(2026);

        $request->refresh();
        $this->assertSame('dikembalikan_karena_rollover', $request->status);
        $this->assertSame(2026, $request->rollover_source_year);
        $this->assertSame(2027, $request->rollover_target_year);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $request->id)
            ->sum('amount'));
        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $request->id,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RELEASED,
            'amount' => -3,
            'dedup_key' => "leave_reservation:{$request->id}:released:rollover:2026",
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'LeaveRequest',
            'auditable_id' => $request->id,
        ]);
        $this->assertSame($stepId, $request->steps()->value('id'));
        $this->assertSame('active', $request->steps()->value('status'));
        $this->assertSame(0, app(ListPendingLeaveApprovalsAction::class)->execute($approver->id)->total());

        $notification = SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'cuti.dikembalikan_karena_rollover')
            ->sole();
        $this->assertSame($request->id, $notification->data['leave_request_id']);
        $this->assertSame('Pengajuan dikembalikan karena rollover saldo cuti tahunan.', $notification->data['reason']);
        $this->assertSame(2026, $notification->data['source_year']);
        $this->assertSame(2027, $notification->data['target_year']);
        $this->assertSame(route('cuti.show', ['id' => $request->id], false), $notification->data['url']);

        $service->rolloverYear(2026);

        $this->assertSame(1, LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $request->id)
            ->where('dedup_key', "leave_reservation:{$request->id}:released:rollover:2026")
            ->count());
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $request->id)
            ->where('event', 'UPDATE')
            ->count());
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'cuti.dikembalikan_karena_rollover')
            ->count());
    }

    public function test_rollover_leaves_non_annual_and_terminal_requests_untouched(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026));
        $annual = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        $nonAnnual = RefJenisCuti::where('code', 'sakit')->firstOrFail();
        $terminal = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $annual->id,
            'tanggal_mulai' => '2026-12-22',
            'tanggal_selesai' => '2026-12-22',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Pengajuan final tidak boleh berubah.',
            'status' => 'disetujui',
        ]);
        $nonAnnualRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $nonAnnual->id,
            'tanggal_mulai' => '2026-12-23',
            'tanggal_selesai' => '2026-12-23',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Cuti non-tahunan tidak boleh berubah.',
            'status' => 'menunggu_approval',
        ]);
        $saldoReducingNonAnnual = RefJenisCuti::create([
            'nama' => 'Cuti Pengurang Saldo Selain Tahunan',
            'code' => 'pengurang_saldo_lain',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $saldoReducingNonAnnualRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $saldoReducingNonAnnual->id,
            'tanggal_mulai' => '2026-12-24',
            'tanggal_selesai' => '2026-12-24',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Jenis selain Tahunan tidak masuk workflow rollover.',
            'status' => 'menunggu_approval',
        ]);

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $this->assertSame('disetujui', $terminal->fresh()->status);
        $this->assertNull($terminal->fresh()->rollover_source_year);
        $this->assertSame('menunggu_approval', $nonAnnualRequest->fresh()->status);
        $this->assertNull($nonAnnualRequest->fresh()->rollover_source_year);
        $this->assertSame('menunggu_approval', $saldoReducingNonAnnualRequest->fresh()->status);
        $this->assertNull($saldoReducingNonAnnualRequest->fresh()->rollover_source_year);
    }

    /**
     * Rollover hanya mengembalikan pengajuan Cuti Tahunan resmi. Jenis pengurang saldo lain yang
     * tertinggal aktif tidak boleh memotong saldo tahun sumber setelah sisa harinya terbawa sebagai
     * carry-over, karena hari yang sama akan terpakai dua kali.
     */
    public function test_saldo_reducing_request_cannot_deduct_source_year_after_rollover(): void
    {
        $employee = $this->employeeWithAppointment();
        $sourceBalance = LeaveBalance::create($this->balancePayload($employee, 2026));
        $saldoReducingNonAnnual = RefJenisCuti::create([
            'nama' => 'Cuti Pengurang Saldo Tanpa Pengembalian Rollover',
            'code' => 'pengurang_saldo_tanpa_pengembalian',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $saldoReducingNonAnnual->id,
            'tanggal_mulai' => '2026-12-24',
            'tanggal_selesai' => '2026-12-24',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Jenis pengurang saldo selain Tahunan tertinggal aktif saat rollover.',
            'status' => 'menunggu_approval',
        ]);
        $service = app(LeaveBalanceService::class);
        $service->rolloverYear(2026);

        $summaryBefore = $sourceBalance->fresh()->only([
            'carry_over', 'terpakai', 'sisa', 'sisa_n2', 'sisa_n1', 'sisa_tahun_berjalan', 'terpakai_tahun_berjalan', 'hangus',
        ]);

        try {
            $service->deductForFinalApproval($request->fresh());
            $this->fail('Pemotongan saldo pada tahun yang sudah di-rollover wajib ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Pengajuan ini tidak dapat disetujui karena saldo tahun pengajuan sudah ditutup oleh rollover. Ajukan kembali pada tahun berjalan agar saldo yang dipakai sesuai.',
                $exception->validator->errors()->first('status'),
            );
        }

        $this->assertSame(0, LeaveBalanceLedger::query()
            ->where('leave_request_id', $request->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED)
            ->count());
        $this->assertSame($summaryBefore, $sourceBalance->fresh()->only([
            'carry_over', 'terpakai', 'sisa', 'sisa_n2', 'sisa_n1', 'sisa_tahun_berjalan', 'terpakai_tahun_berjalan', 'hangus',
        ]));
    }

    public function test_rollover_notifies_affected_employee_once_when_multiple_active_requests_are_returned(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create(['employee_id' => $employee->id]);
        $sourceBalance = LeaveBalance::create($this->balancePayload($employee, 2026));
        $firstRequest = $this->activeAnnualLeaveRequest($employee, 2026, 2, '2026-12-21');
        $secondRequest = $this->activeAnnualLeaveRequest($employee, 2026, 3, '2026-12-22');
        $this->reserveRequest($firstRequest, $sourceBalance, 2, $actor);
        $this->reserveRequest($secondRequest, $sourceBalance, 3, $actor);

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $notification = SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'cuti.dikembalikan_karena_rollover')
            ->sole();
        $this->assertContains($notification->data['leave_request_id'], [$firstRequest->id, $secondRequest->id]);
        $this->assertEqualsCanonicalizing([$firstRequest->id, $secondRequest->id], $notification->data['leave_request_ids']);
        $this->assertSame(
            route('cuti.show', ['id' => $notification->data['leave_request_id']], false),
            $notification->data['url'],
        );
    }

    /** @return array<string, array{string}> */
    public static function activeAnnualLeaveStatusProvider(): array
    {
        return [
            'menunggu approval' => ['menunggu_approval'],
            'ditangguhkan' => ['ditangguhkan'],
            'perlu perubahan' => ['perlu_perubahan'],
        ];
    }

    public function test_rollover_dua_tahun_tanpa_cuti_tahunan_mengizinkan_total_dua_puluh_empat_hari(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'carry_over' => 6,
            'sisa' => 18,
        ]));

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(6, $balance->sisa_n2);
        $this->assertSame(6, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(24, $balance->sisa);
        $this->assertSame(6, $balance->hangus);
    }

    public function test_rollover_cuti_tahunan_sebagian_mematahkan_cap_dua_tahun(): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 10,
            'carry_over' => 6,
            'terpakai_tahun_berjalan' => 2,
            'terpakai' => 2,
            'sisa' => 16,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisTahunan->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-07',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Cuti tahunan sebagian.',
            'status' => 'disetujui',
        ]);

        $service->rolloverYear(2026);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(0, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(12, $balance->sisa);
        $this->assertSame(16, $balance->hangus);

        $service->rolloverYear(2026);

        $this->assertSame(1, LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('event_type', 'rollover_applied')->count());
        $this->assertSame(1, LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('event_type', 'annual_entitlement_granted')->count());
        $this->assertSame(1, LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('event_type', 'carry_over_expired')->count());
        $this->assertSame(0, LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('event_type', 'carry_over_granted')->count());
        $this->assertSame(1, AuditLog::where('event', 'LEAVE_ROLLOVER_APPLIED')
            ->where('auditable_id', $balance->id)->count());

        $expired = LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('event_type', 'carry_over_expired')->firstOrFail();
        $this->assertSame(2026, $expired->source_year);
        $this->assertSame(16, $expired->metadata['expired_days']);
        $this->assertSame(2027, $expired->metadata['target_year']);

        $audit = AuditLog::where('event', 'LEAVE_ROLLOVER_APPLIED')
            ->where('auditable_id', $balance->id)->firstOrFail();
        $this->assertSame($employee->id, $audit->new_values['employee_id']);
        $this->assertSame(2027, $audit->new_values['tahun']);
        $this->assertSame(2026, $audit->new_values['tahun_sumber']);
        $this->assertSame(0, $audit->new_values['carry_over']);
        $this->assertSame(16, $audit->new_values['hangus']);
    }

    /**
     * @return array<string, array{0:array<int, int>}>
     */
    public static function approvedAnnualBucketProvider(): array
    {
        return [
            'N-2' => [[2024]],
            'N-1' => [[2025]],
            'current' => [[2026]],
            'kombinasi N-2, N-1, dan current' => [[2024, 2025, 2026]],
        ];
    }

    #[DataProvider('approvedAnnualBucketProvider')]
    public function test_approval_dari_bucket_mana_pun_menggagalkan_carry_ordinary(array $deductionSourceYears): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        $sourceBalance = LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 7,
            'carry_over' => 6,
            'sisa' => 13,
        ]));
        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisTahunan->id,
            'tanggal_mulai' => '2026-06-15',
            'tanggal_selesai' => '2026-06-15',
            'jumlah_hari_kerja' => count($deductionSourceYears),
            'alasan' => 'Cuti tahunan memakai bucket sumber teruji.',
            'status' => 'disetujui',
        ]);

        foreach ($deductionSourceYears as $deductionSourceYear) {
            LeaveBalanceLedger::create([
                'employee_id' => $employee->id,
                'leave_request_id' => $request->id,
                'leave_balance_id' => $sourceBalance->id,
                'tahun' => 2026,
                'event_type' => 'leave_deducted',
                'amount' => -1,
                'source_year' => $deductionSourceYear,
                'reason' => 'Fixture pemotongan dari bucket sumber.',
                'dedup_key' => "leave_deducted:rule1:{$request->id}:{$deductionSourceYear}",
                'metadata' => ['bucket' => $deductionSourceYear === 2026 ? 'current' : ($deductionSourceYear === 2025 ? 'n1' : 'n2')],
                'occurred_at' => Carbon::parse('2026-06-15 17:00:00'),
            ]);
        }

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $target->sisa_n2);
        $this->assertSame(0, $target->sisa_n1);
        $this->assertSame(12, $target->sisa);
        $this->assertSame(13, $target->hangus);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'carry_over_granted',
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function nonApprovedAnnualStatusProvider(): array
    {
        return [
            'pending approval' => ['menunggu_approval'],
            'generic postpone' => ['ditangguhkan'],
            'needs changes' => ['perlu_perubahan'],
            'not approved' => ['tidak_disetujui'],
        ];
    }

    #[DataProvider('nonApprovedAnnualStatusProvider')]
    public function test_status_selain_disetujui_tidak_menggagalkan_carry_ordinary(string $status): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 7,
            'sisa' => 7,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisTahunan->id,
            'tanggal_mulai' => '2026-09-14',
            'tanggal_selesai' => '2026-09-14',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture status non-final Rule 1.',
            'status' => $status,
        ]);

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(6, $target->sisa_n1);
        $this->assertSame(18, $target->sisa);
        $this->assertSame(1, $target->hangus);
    }

    public function test_approval_di_luar_half_open_source_year_tidak_menggagalkan_rule_satu(): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 6,
            'sisa' => 6,
        ]));

        foreach (['2025-12-31', '2027-01-01'] as $tanggalMulai) {
            LeaveRequest::create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $jenisTahunan->id,
                'tanggal_mulai' => $tanggalMulai,
                'tanggal_selesai' => $tanggalMulai,
                'jumlah_hari_kerja' => 1,
                'alasan' => 'Fixture batas tahun Rule 1.',
                'status' => 'disetujui',
            ]);
        }

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(6, $target->sisa_n1);
        $this->assertSame(18, $target->sisa);
        $this->assertSame(0, $target->hangus);
    }

    public function test_tahun_rule_satu_ditentukan_oleh_tanggal_mulai(): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 6,
            'sisa' => 6,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisTahunan->id,
            'tanggal_mulai' => '2026-12-31',
            'tanggal_selesai' => '2026-12-31',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture akhir tahun sumber.',
            'status' => 'disetujui',
        ]);

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $target->sisa_n1);
        $this->assertSame(12, $target->sisa);
        $this->assertSame(6, $target->hangus);
    }

    public function test_approval_tahun_sebelumnya_mematahkan_rule_dua_tetapi_rule_satu_tahun_sumber_tetap_berlaku(): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 7,
            'carry_over' => 6,
            'sisa' => 13,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisTahunan->id,
            'tanggal_mulai' => '2025-04-07',
            'tanggal_selesai' => '2025-04-07',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Approval pada tahun sebelum source year.',
            'status' => 'disetujui',
        ]);

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $target->sisa_n2);
        $this->assertSame(6, $target->sisa_n1);
        $this->assertSame(18, $target->sisa);
        $this->assertSame(7, $target->hangus);
    }

    public function test_carry_statutory_tetap_diproses_saat_approval_menggagalkan_ordinary_carry(): void
    {
        $employee = $this->employeeWithAppointment();
        $jenisTahunan = RefJenisCuti::where('code', 'tahunan')->firstOrFail();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 7,
            'sisa' => 7,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisTahunan->id,
            'tanggal_mulai' => '2026-05-11',
            'tanggal_selesai' => '2026-05-11',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Approval yang mematahkan ordinary carry.',
            'status' => 'disetujui',
        ]);
        $protectedRequest = $this->annualLeaveRequest($employee, 2026, 4);
        $service->recordDutyPostponement($protectedRequest, $actor, 'Penugasan mendesak kantor');

        $service->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $target->sisa_n2);
        $this->assertSame(4, $target->sisa_n1);
        $this->assertSame(16, $target->sisa);
        $this->assertSame(3, $target->hangus);
        $carry = LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('tahun', 2027)
            ->where('event_type', 'carry_over_granted')
            ->firstOrFail();
        $this->assertSame(4, $carry->amount);
        $this->assertSame(4, $carry->metadata['duty_postponed_carried']);
    }

    public function test_rollover_memperbarui_ringkasan_target_yang_sudah_ada(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 5,
            'sisa' => 5,
        ]));
        LeaveBalance::create($this->balancePayload($employee, 2027, [
            'sisa_tahun_berjalan' => 12,
            'sisa' => 12,
        ]));

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(5, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(17, $balance->sisa);
    }

    public function test_rollover_target_yang_sudah_terpakai_tidak_mengembalikan_hari_yang_sudah_dipotong(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 5,
            'sisa' => 5,
        ]));
        LeaveBalance::create($this->balancePayload($employee, 2027, [
            'terpakai' => 3,
            'terpakai_tahun_berjalan' => 3,
            'sisa_tahun_berjalan' => 9,
            'sisa' => 9,
        ]));

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(5, $balance->sisa_n1);
        $this->assertSame(9, $balance->sisa_tahun_berjalan);
        $this->assertSame(14, $balance->sisa);
        $this->assertSame(3, $balance->terpakai_tahun_berjalan);
    }

    public function test_rollover_terlambat_melewati_target_yang_sudah_dibuka_admin_tanpa_mutasi(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 6,
            'sisa' => 6,
        ]));
        $service->setOpeningBalance($employee, 2027, [
            'n2' => 1,
            'n1' => 2,
            'current' => 10,
        ], 'Pembukaan admin sebelum rollover terlambat.', $actor);
        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $summaryBefore = $this->summaryPayload($target);
        $ledgerCount = LeaveBalanceLedger::count();
        $auditCount = AuditLog::count();

        $service->rolloverYear(2026);

        $this->assertSame($summaryBefore, $this->summaryPayload($target->fresh()));
        $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
        ]);
    }

    public function test_rollover_mengunci_request_lalu_pegawai_sebelum_saldo_dan_retry_tidak_menulis_ulang(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Urutan row lock rollover diverifikasi dengan SQL PostgreSQL.');
        }

        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create(['employee_id' => $employee->id]);
        $service = app(LeaveBalanceService::class);
        $sourceBalance = LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 5,
            'sisa' => 5,
        ]));
        $request = $this->activeAnnualLeaveRequest($employee, 2026, 1, '2026-12-22');
        $this->reserveRequest($request, $sourceBalance, 1, $actor);
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $service->rolloverYear(2026);

        $requestLockIndex = collect($queries)->search(
            fn (string $sql): bool => str_contains($sql, 'from "leave_requests"') && str_contains($sql, 'for update'),
        );
        $employeeLockIndex = collect($queries)->search(
            fn (string $sql): bool => str_contains($sql, 'from "employees"') && str_contains($sql, 'for update'),
        );
        $balanceLockIndex = collect($queries)->search(
            fn (string $sql): bool => str_contains($sql, 'from "leave_balances"') && str_contains($sql, 'for update'),
        );
        $this->assertIsInt($requestLockIndex);
        $this->assertIsInt($employeeLockIndex);
        $this->assertIsInt($balanceLockIndex);
        $this->assertLessThan($employeeLockIndex, $requestLockIndex);
        $this->assertLessThan($balanceLockIndex, $employeeLockIndex);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $summaryBefore = $this->summaryPayload($target);
        $ledgerCount = LeaveBalanceLedger::count();
        $auditCount = AuditLog::count();
        $service->rolloverYear(2026);
        $this->assertSame($summaryBefore, $this->summaryPayload($target->fresh()));
        $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_rollover_terlambat_mempertahankan_koreksi_target_yang_diinisialisasi_sistem(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 5,
            'sisa' => 5,
        ]));
        $this->assertSame(12, $service->availableFor($employee, 2027, Carbon::parse('2027-01-01')));
        $service->adjustBalance($employee, 2027, 'current', 3, 'Koreksi sebelum rollover terlambat.', $actor);

        $service->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $target->sisa_n2);
        $this->assertSame(5, $target->sisa_n1);
        $this->assertSame(15, $target->sisa_tahun_berjalan);
        $this->assertSame(20, $target->sisa);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT,
            'amount' => 3,
        ]);
    }

    public function test_penangguhan_dinas_dibawa_satu_tahun_dan_rollover_idempotent(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 10,
            'sisa' => 10,
        ]));
        $request = $this->annualLeaveRequest($employee, 2026, 8);

        $first = $service->recordDutyPostponement($request, $actor, 'Penugasan mendesak kantor');
        $second = $service->recordDutyPostponement($request, $actor, 'Penugasan mendesak kantor');
        $service->rolloverYear(2026);
        $service->rolloverYear(2026);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(10, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(22, $balance->sisa);
        $this->assertSame(0, $balance->hangus);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, LeaveBalanceLedger::where('leave_request_id', $request->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)->count());
        $this->assertSame(4, LeaveBalanceLedger::where('employee_id', $employee->id)->count());
        $this->assertSame(22, LeaveBalanceLedger::where('employee_id', $employee->id)->where('tahun', 2027)->sum('amount'));
    }

    public function test_penangguhan_dinas_menyimpan_kontrak_request_aktor_dan_alokasi_bucket(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 6,
            'carry_over' => 6,
            'sisa' => 12,
        ]));
        $request = $this->annualLeaveRequest($employee, 2026, 8);

        app(LeaveBalanceService::class)->recordDutyPostponement($request, $actor, 'Penugasan mendesak kantor');

        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'leave_request_id' => $request->id,
            'tahun' => 2026,
            'event_type' => 'duty_postponement_recorded',
            'amount' => 0,
            'source_year' => 2026,
            'created_by' => $actor->id,
        ]);
        $ledger = LeaveBalanceLedger::where('event_type', 'duty_postponement_recorded')->firstOrFail();
        $this->assertSame([
            'request_id' => $request->id,
            'protected_days' => 8,
            'protected_allocations' => ['n2' => 0, 'n1' => 2, 'current' => 6],
            'source_request_workdays' => 8,
            'source_status' => LeaveRequest::STATUS_DUTY_POSTPONED,
            'expiry_policy' => 'valid_one_year_no_n2_aging',
        ], $ledger->metadata);
    }

    public function test_service_retry_menolak_source_facts_metadata_yang_tidak_cocok(): void
    {
        foreach (['source_request_workdays' => 99, 'source_status' => 'disetujui'] as $fact => $corruptedValue) {
            $employee = $this->employeeWithAppointment();
            $actor = User::factory()->create();
            $service = app(LeaveBalanceService::class);
            LeaveBalance::create($this->balancePayload($employee, 2026));
            $request = $this->annualLeaveRequest($employee, 2026, 2);
            $ledger = $service->recordDutyPostponement($request, $actor, 'Penugasan mendesak kantor');
            $metadata = $ledger->metadata;
            $metadata[$fact] = $corruptedValue;
            DB::table('leave_balance_ledger')
                ->where('id', $ledger->id)
                ->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
            $ledgerCount = LeaveBalanceLedger::count();

            try {
                $service->recordDutyPostponement($request, $actor, 'Penugasan mendesak kantor');
                $this->fail("Retry service dengan metadata {$fact} rusak wajib ditolak.");
            } catch (ValidationException) {
                $this->assertSame($ledgerCount, LeaveBalanceLedger::count());
            }
        }
    }

    public function test_penangguhan_dinas_hangus_setelah_satu_tahun_dan_tidak_menjadi_n_dua(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 8,
            'sisa' => 8,
        ]));
        $request = $this->annualLeaveRequest($employee, 2026, 8);

        $service->recordDutyPostponement($request, $actor, 'Penugasan mendesak kantor');
        $service->rolloverYear(2026);
        $service->rolloverYear(2027);

        $balance = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2028)->firstOrFail();
        $this->assertSame(0, $balance->sisa_n2);
        $this->assertSame(6, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(18, $balance->sisa);
        $this->assertSame(14, $balance->hangus);
    }

    public function test_penangguhan_dinas_yang_sudah_dipakai_tidak_dihanguskan_ulang_atau_menua_ke_n_dua(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 8,
            'sisa' => 8,
        ]));
        $protectedRequest = $this->annualLeaveRequest($employee, 2026, 8);
        $service->recordDutyPostponement($protectedRequest, $actor, 'Penugasan mendesak kantor');
        $service->rolloverYear(2026);

        $usedRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::where('code', 'tahunan')->firstOrFail()->id,
            'tanggal_mulai' => '2027-04-05',
            'tanggal_selesai' => '2027-04-12',
            'jumlah_hari_kerja' => 8,
            'alasan' => 'Memakai seluruh carry statutory yang masih berlaku.',
            'status' => 'disetujui',
        ])->load('jenisCuti');
        $service->deductForFinalApproval($usedRequest);

        $source = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $source->sisa_n1);
        $this->assertSame(12, $source->sisa_tahun_berjalan);

        $service->rolloverYear(2027);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2028)->firstOrFail();
        $this->assertSame(0, $target->sisa_n2);
        $this->assertSame(0, $target->sisa_n1);
        $this->assertSame(12, $target->sisa_tahun_berjalan);
        $this->assertSame(12, $target->sisa);
        $this->assertSame(12, $target->hangus);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2028,
            'event_type' => LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED,
        ]);
    }

    public function test_penangguhan_baru_tidak_boleh_melindungi_ulang_carry_statutory_yang_akan_hangus(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 8,
            'sisa' => 8,
        ]));
        $service->recordDutyPostponement(
            $this->annualLeaveRequest($employee, 2026, 8),
            $actor,
            'Penugasan tahun sumber.',
        );
        $service->rolloverYear(2026);
        $this->assertSame(20, $service->availableFor($employee, 2027, Carbon::parse('2027-06-01')));

        $newRequest = $this->annualLeaveRequest($employee, 2027, 13);

        try {
            $service->recordDutyPostponement($newRequest, $actor, 'Mencoba melindungi ulang carry statutory.');
            $this->fail('Carry statutory yang akan hangus tidak boleh menjadi sumber perlindungan baru.');
        } catch (ValidationException) {
            $this->assertSame(0, LeaveBalanceLedger::where('leave_request_id', $newRequest->id)
                ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)->count());
        }
    }

    public function test_penangguhan_baru_boleh_memakai_n_satu_ordinary_tanpa_melindungi_ulang_statutory(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 10,
            'sisa' => 10,
        ]));
        $service->recordDutyPostponement(
            $this->annualLeaveRequest($employee, 2026, 8),
            $actor,
            'Penugasan tahun sumber.',
        );
        $service->rolloverYear(2026);

        $newRequest = $this->annualLeaveRequest($employee, 2027, 14);
        $ledger = $service->recordDutyPostponement($newRequest, $actor, 'Penugasan tahun target.');
        $this->assertSame(
            ['n2' => 0, 'n1' => 2, 'current' => 12],
            $ledger->metadata['protected_allocations'],
        );

        $service->rolloverYear(2027);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2028)->firstOrFail();
        $this->assertSame(0, $target->sisa_n2);
        $this->assertSame(12, $target->sisa_n1);
        $this->assertSame(12, $target->sisa_tahun_berjalan);
        $this->assertSame(24, $target->sisa);
        $this->assertSame(10, $target->hangus);
        $carry = LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('tahun', 2028)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED)
            ->firstOrFail();
        $this->assertSame(12, $carry->metadata['duty_postponed_carried']);
    }

    public function test_penangguhan_dinas_reverse_allocation_membawa_hak_meski_rule_dua_false(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 6,
            'carry_over' => 6,
            'sisa' => 12,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::where('code', 'tahunan')->firstOrFail()->id,
            'tanggal_mulai' => '2025-03-03',
            'tanggal_selesai' => '2025-03-03',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Mematahkan Rule 2.',
            'status' => 'disetujui',
        ]);
        $request = $this->annualLeaveRequest($employee, 2026, 8);

        $service->recordDutyPostponement($request, $actor, 'Penugasan mendesak kantor');
        $service->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(0, $target->sisa_n2);
        $this->assertSame(8, $target->sisa_n1);
        $this->assertSame(12, $target->sisa_tahun_berjalan);
        $this->assertSame(20, $target->sisa);
        $this->assertSame(4, $target->hangus);
    }

    public function test_penangguhan_dinas_berbagi_cap_dua_puluh_empat_dengan_carry_ordinary(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_n2' => 6,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'carry_over' => 12,
            'sisa' => 24,
        ]));
        $request = $this->annualLeaveRequest($employee, 2026, 12);

        $service->recordDutyPostponement($request, $actor, 'Penugasan mendesak kantor');
        $service->rolloverYear(2026);

        $target = LeaveBalance::where('employee_id', $employee->id)->where('tahun', 2027)->firstOrFail();
        $this->assertSame(6, $target->sisa_n2);
        $this->assertSame(6, $target->sisa_n1);
        $this->assertSame(12, $target->sisa_tahun_berjalan);
        $this->assertSame(24, $target->sisa);
        $this->assertSame(6, $target->hangus);
        $carry = LeaveBalanceLedger::where('employee_id', $employee->id)
            ->where('tahun', 2027)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED)
            ->firstOrFail();
        $this->assertSame(6, $carry->metadata['duty_postponed_carried']);
    }

    public function test_penangguhan_dinas_ditolak_setelah_marker_rollover_tanpa_ledger_baru(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2026));
        $request = $this->annualLeaveRequest($employee, 2026, 2);
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'amount' => 0,
            'source_year' => 2026,
            'dedup_key' => "{$employee->id}:2027:rollover_applied",
        ]);

        try {
            app(LeaveBalanceService::class)->recordDutyPostponement($request, $actor, 'Terlambat dicatat.');
            $this->fail('Penangguhan setelah rollover seharusnya ditolak.');
        } catch (ValidationException) {
            $this->assertSame(0, LeaveBalanceLedger::where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)->count());
        }
    }

    public function test_source_year_boundary_uses_rollover_marker_instead_of_calendar_date(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026));
        $beforeMarker = $this->annualLeaveRequest($employee, 2026, 2, '2026-12-31');

        Carbon::setTestNow('2026-12-31 23:59:59');
        $service->recordDutyPostponement($beforeMarker, $actor, 'Penugasan akhir tahun sebelum marker rollover.');

        $this->assertDatabaseHas('leave_balance_ledger', [
            'leave_request_id' => $beforeMarker->id,
            'event_type' => LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED,
        ]);

        $service->rolloverYear(2026);
        Carbon::setTestNow('2027-01-01 00:00:01');
        $afterMarker = $this->annualLeaveRequest($employee, 2026, 1, '2026-12-30');
        $terminalLedgerCount = LeaveBalanceLedger::query()
            ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)
            ->count();

        try {
            $service->recordDutyPostponement($afterMarker, $actor, 'Penugasan setelah marker rollover.');
            $this->fail('Marker rollover harus menolak pencatatan meski request tetap bersumber dari 2026.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('leave_request', $exception->errors());
        }

        $this->assertSame($terminalLedgerCount, LeaveBalanceLedger::query()
            ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)
            ->count());
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'leave_request_id' => $afterMarker->id,
            'event_type' => LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED,
        ]);
    }

    public function test_penangguhan_dinas_melebihi_saldo_unprotected_ditolak_dan_availability_efektif(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $service = app(LeaveBalanceService::class);
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 10,
            'sisa' => 10,
        ]));
        $firstRequest = $this->annualLeaveRequest($employee, 2026, 8);
        $service->recordDutyPostponement($firstRequest, $actor, 'Penugasan pertama.');

        $this->assertSame(2, $service->availableFor($employee, 2026, Carbon::parse('2026-06-01')));
        $preview = $service->previewFor($employee, Carbon::parse('2026-06-01'));
        $this->assertSame(10, $preview['saldo_aktual']);
        $this->assertSame(8, $preview['dilindungi_penangguhan_dinas']);
        $this->assertSame(2, $preview['saldo_dapat_diajukan']);

        $secondRequest = $this->annualLeaveRequest($employee, 2026, 3, '2026-07-01');
        try {
            $service->recordDutyPostponement($secondRequest, $actor, 'Melebihi saldo efektif.');
            $this->fail('Penangguhan melebihi saldo efektif seharusnya ditolak.');
        } catch (ValidationException) {
            $this->assertSame(1, LeaveBalanceLedger::where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)->count());
        }
    }

    public function test_penangguhan_dinas_menolak_hari_yang_sudah_direservasi_request_lain(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 10,
            'sisa' => 10,
        ]));
        $requestA = $this->activeAnnualLeaveRequest($employee, 2026, 8, '2026-05-04');
        $requestB = $this->activeAnnualLeaveRequest($employee, 2026, 3, '2026-06-01');
        $this->reserveRequest($requestA, $balance, 8, $actor);
        $this->reserveRequest($requestB, $balance, 3, $actor);

        try {
            app(LeaveBalanceService::class)->recordDutyPostponement($requestB, $actor, 'Penugasan dinas request B.');
            $this->fail('Hari yang sudah direservasi request lain tidak boleh dilindungi ulang.');
        } catch (ValidationException) {
            $this->assertSame(0, LeaveBalanceLedger::where('leave_request_id', $requestB->id)
                ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)->count());
            $this->assertSame(8, (int) LeaveBalanceReservationEvent::where('leave_request_id', $requestA->id)->sum('amount'));
            $this->assertSame(3, (int) LeaveBalanceReservationEvent::where('leave_request_id', $requestB->id)->sum('amount'));
            $this->assertSame(2, LeaveBalanceReservationEvent::count());
        }
    }

    public function test_penangguhan_dinas_mengecualikan_reservasi_sendiri_dan_memakai_sisa_setelah_request_lain(): void
    {
        $employee = $this->employeeWithAppointment();
        $actor = User::factory()->create();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 10,
            'sisa' => 10,
        ]));
        $requestA = $this->activeAnnualLeaveRequest($employee, 2026, 8, '2026-05-04');
        $requestB = $this->activeAnnualLeaveRequest($employee, 2026, 2, '2026-06-01');
        $this->reserveRequest($requestA, $balance, 8, $actor);
        $this->reserveRequest($requestB, $balance, 2, $actor);

        $ledger = app(LeaveBalanceService::class)->recordDutyPostponement(
            $requestB,
            $actor,
            'Penugasan dinas request B.',
        );

        $this->assertSame(['n2' => 0, 'n1' => 0, 'current' => 2], $ledger->metadata['protected_allocations']);
        $this->assertSame(8, (int) LeaveBalanceReservationEvent::where('leave_request_id', $requestA->id)->sum('amount'));
        $this->assertSame(2, (int) LeaveBalanceReservationEvent::where('leave_request_id', $requestB->id)->sum('amount'));
        $this->assertSame(2, LeaveBalanceReservationEvent::count());
        $this->assertSame(10, $balance->fresh()->sisa);
    }

    public function test_cuti_besar_mencegah_jatah_tahunan_otomatis_di_tahun_yang_sama(): void
    {
        $employee = $this->employeeWithAppointment('2024-01-01');
        $cutiBesar = RefJenisCuti::where('code', 'besar')->firstOrFail();
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $cutiBesar->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-31',
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti besar.',
            'status' => 'disetujui',
        ]);

        $available = app(LeaveBalanceService::class)->availableFor($employee, 2026, Carbon::parse('2026-08-03'));

        $this->assertSame(0, $available);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'event_type' => 'annual_entitlement_granted',
        ]);
    }

    public function test_final_cuti_besar_gagal_jika_cuti_tahunan_sudah_terpotong_di_tahun_sama(): void
    {
        $employee = $this->employeeWithAppointment();
        $cutiBesar = RefJenisCuti::where('code', 'besar')->firstOrFail();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 10,
            'terpakai_tahun_berjalan' => 2,
            'terpakai' => 2,
            'sisa' => 10,
        ]));
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => 'leave_deducted',
            'amount' => -2,
            'source_year' => 2026,
            'reason' => 'Pemotongan cuti tahunan sebelum cuti besar.',
            'dedup_key' => "leave_deducted:test:{$employee->id}:2026",
            'occurred_at' => Carbon::parse('2026-07-01'),
        ]);

        try {
            app(LeaveBalanceService::class)->assertCutiBesarCanBeFinallyApproved($employee->id, 2026);
            $this->fail('Cuti Besar harus ditolak setelah pemotongan Cuti Tahunan tahun yang sama.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertSame(
                'Cuti Besar tidak dapat disetujui karena Cuti Tahunan tahun yang sama sudah digunakan.',
                $exception->errors()['status'][0],
            );
        }
    }

    public function test_final_cuti_besar_gagal_jika_cuti_tahunan_tahun_sama_memakai_carry_over(): void
    {
        $employee = $this->employeeWithAppointment();
        $balance = LeaveBalance::create($this->balancePayload($employee, 2026, [
            'carry_over' => 6,
            'terpakai' => 3,
            'sisa_n1' => 3,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'sisa' => 15,
        ]));
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => 'leave_deducted',
            'amount' => -3,
            'source_year' => 2025,
            'reason' => 'Pemotongan cuti tahunan dari carry-over sebelum cuti besar.',
            'dedup_key' => "leave_deducted:test:{$employee->id}:2025",
            'occurred_at' => Carbon::parse('2026-02-01'),
        ]);

        try {
            app(LeaveBalanceService::class)->assertCutiBesarCanBeFinallyApproved($employee->id, 2026);
            $this->fail('Cuti Besar harus ditolak setelah pemotongan carry-over pada tahun penggunaan yang sama.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertSame(
                'Cuti Besar tidak dapat disetujui karena Cuti Tahunan tahun yang sama sudah digunakan.',
                $exception->errors()['status'][0],
            );
        }
    }

    public function test_rollover_tahun_cuti_besar_tidak_membawa_current_ke_n_minus_one(): void
    {
        $employee = $this->employeeWithAppointment();
        $source = LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 12,
            'sisa' => 12,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::where('code', 'besar')->firstOrFail()->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-31',
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar final.',
            'status' => 'disetujui',
        ]);

        $service = app(LeaveBalanceService::class);
        $service->rolloverYear(2026);

        $target = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2027)
            ->firstOrFail();
        $this->assertSame(0, $target->sisa_n1);
        $this->assertSame(12, $target->sisa_tahun_berjalan);
        $this->assertSame(12, $target->sisa);
        $this->assertSame(12, $target->hangus);
        $this->assertSame(12, $source->fresh()->sisa_tahun_berjalan);
        $this->assertSame(12, $source->fresh()->sisa);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED,
            'source_year' => 2026,
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_ANNUAL_ENTITLEMENT_GRANTED,
            'amount' => 12,
        ]);

        $rollover = LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2027)
            ->where('event_type', LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED)
            ->firstOrFail();
        $expired = LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2027)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED)
            ->firstOrFail();
        $this->assertSame(12, $rollover->metadata['rule_5_current_excluded']);
        $this->assertSame(12, $expired->metadata['expired_days']);

        $service->rolloverYear(2026);

        $this->assertSame(1, LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED)
            ->count());
        $this->assertSame(1, LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED)
            ->count());
    }

    public function test_rollover_tahun_cuti_besar_hanya_mengecualikan_current_dan_tidak_memperpanjang_carry_lama(): void
    {
        $employee = $this->employeeWithAppointment();
        $source = LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_n2' => 4,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'carry_over' => 10,
            'sisa' => 22,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::where('code', 'besar')->firstOrFail()->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-31',
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar final.',
            'status' => 'disetujui',
        ]);

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $target = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2027)
            ->firstOrFail();
        $this->assertSame(4, $source->fresh()->sisa_n2);
        $this->assertSame(6, $source->fresh()->sisa_n1);
        $this->assertSame(12, $source->fresh()->sisa_tahun_berjalan);
        $this->assertSame(6, $target->sisa_n2);
        $this->assertSame(0, $target->sisa_n1);
        $this->assertSame(12, $target->sisa_tahun_berjalan);
        $this->assertSame(18, $target->sisa);
        $this->assertSame(12, $target->hangus);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED,
            'amount' => 6,
            'source_year' => 2026,
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED,
            'source_year' => 2026,
            'metadata->expired_days' => 12,
        ]);
    }

    public function test_rollover_cuti_besar_non_final_tidak_mengubah_carry_ordinary(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 12,
            'sisa' => 12,
        ]));
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::where('code', 'besar')->firstOrFail()->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-31',
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar belum final.',
            'status' => 'menunggu_approval',
        ]);

        app(LeaveBalanceService::class)->rolloverYear(2026);

        $target = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2027)
            ->firstOrFail();
        $this->assertSame(6, $target->sisa_n1);
        $this->assertSame(12, $target->sisa_tahun_berjalan);
        $this->assertSame(18, $target->sisa);
        $this->assertSame(6, $target->hangus);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED,
            'amount' => 6,
            'source_year' => 2026,
        ]);
    }

    public function test_command_rollover_memakai_tahun_sumber_dan_scheduler_berjalan_jam_nol_nol_lima(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 3,
            'sisa' => 3,
        ]));

        $this->artisan('cuti:rollover 2026')
            ->expectsOutput('Rollover saldo cuti tahun 2026 ke 2027 selesai.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'sisa_n1' => 3,
            'sisa_tahun_berjalan' => 12,
            'sisa' => 15,
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => 'annual_entitlement_granted',
            'amount' => 12,
            'source_year' => 2027,
            'dedup_key' => "{$employee->id}:2027:annual_entitlement_granted",
        ]);

        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'cuti:rollover'));

        $this->assertNotNull($event);
        $this->assertSame('5 0 1 1 *', $event->expression);
        $this->assertSame(config('app.timezone'), $event->timezone);
    }

    public function test_command_rollover_tanpa_argumen_memakai_tahun_sebelumnya(): void
    {
        $employee = $this->employeeWithAppointment();
        LeaveBalance::create($this->balancePayload($employee, 2026, [
            'sisa_tahun_berjalan' => 2,
            'sisa' => 2,
        ]));

        $this->artisan('cuti:rollover')
            ->expectsOutput('Rollover saldo cuti tahun 2026 ke 2027 selesai.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'sisa_n1' => 2,
            'sisa' => 14,
        ]);
    }

    public function test_command_rollover_menolak_tahun_tidak_valid_sebelum_query_database(): void
    {
        $this->artisan('cuti:rollover bukan-tahun')
            ->expectsOutput('Tahun sumber rollover harus berupa tahun 4 digit, contoh: 2026.')
            ->assertExitCode(1);
    }

    private function employeeWithAppointment(string $tmt = '2020-01-01'): Employee
    {
        $employee = Employee::factory()->create();
        $employee->appointment()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-TEST',
            'tanggal_sk' => $tmt,
        ]);

        return $employee;
    }

    private function annualLeaveRequest(Employee $employee, int $year, int $days, ?string $start = null): LeaveRequest
    {
        $start ??= "{$year}-06-01";

        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::where('code', 'tahunan')->firstOrFail()->id,
            'tanggal_mulai' => $start,
            'tanggal_selesai' => $start,
            'jumlah_hari_kerja' => $days,
            'alasan' => 'Cuti tahunan ditangguhkan karena tugas dinas.',
            'status' => LeaveRequest::STATUS_DUTY_POSTPONED,
        ]);
    }

    private function activeAnnualLeaveRequest(Employee $employee, int $year, int $days, string $start): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::where('code', 'tahunan')->firstOrFail()->id,
            'tanggal_mulai' => $start,
            'tanggal_selesai' => $start,
            'jumlah_hari_kerja' => $days,
            'alasan' => "Reservasi aktif tahun {$year}.",
            'status' => 'menunggu_approval',
        ]);
    }

    private function reserveRequest(LeaveRequest $request, LeaveBalance $balance, int $days, User $actor): void
    {
        LeaveBalanceReservationEvent::create([
            'employee_id' => $request->employee_id,
            'leave_request_id' => $request->id,
            'leave_balance_id' => $balance->id,
            'tahun' => $request->tanggal_mulai->year,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => $days,
            'reason' => 'Fixture reservasi aktif append-only.',
            'dedup_key' => "leave_reservation:{$request->id}:reserved",
            'metadata' => ['requested_days' => $days],
            'created_by' => $actor->id,
            'occurred_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array<string, int>  $overrides
     * @return array<string, mixed>
     */
    private function balancePayload(Employee $employee, int $tahun, array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $employee->id,
            'tahun' => $tahun,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ], $overrides);
    }

    /** @return array<string, int> */
    private function summaryPayload(LeaveBalance $balance): array
    {
        return $balance->only([
            'jatah_awal',
            'carry_over',
            'terpakai',
            'sisa',
            'sisa_n2',
            'sisa_n1',
            'sisa_tahun_berjalan',
            'terpakai_tahun_berjalan',
            'hangus',
        ]);
    }
}
