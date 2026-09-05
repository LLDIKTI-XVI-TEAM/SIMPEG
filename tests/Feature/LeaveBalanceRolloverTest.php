<?php

namespace Tests\Feature;

use App\Actions\Cuti\ListPendingLeaveApprovalsAction;
use App\Actions\Cuti\RolloverLeaveBalanceAction;
use App\Jobs\SendSimpegNotificationEmailJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use App\Services\Cuti\LeaveUsageReconciliationService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Menjaga orkestrasi rollover: bounded iteration, lock order, lifecycle request,
 * notifikasi, CLI, dan scheduler. Matematika ceiling/FIFO/expiry diuji terpisah
 * oleh AnnualLeaveCeilingTest dan LeaveBalanceRecalculationTest, sedangkan Rule 3,
 * Rule 5, atomicity, serta late approval diuji oleh suite workflow dan cutover.
 */
class LeaveBalanceRolloverTest extends TestCase
{
    use RefreshDatabase;

    private User $reconciliationActor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        Carbon::setTestNow('2027-01-01 00:05:00');
        Queue::fake();
        $this->reconciliationActor = User::factory()->adminKepegawaian()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('activeAnnualLeaveStatusProvider')]
    public function test_rollover_returns_every_active_annual_status_and_preserves_step_snapshot(string $status): void
    {
        $employee = $this->reconciledEmployee();
        $approver = Employee::factory()->create();
        $request = $this->annualRequest($employee, $status, 3, '2026-12-21');
        $request->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        $stepId = $request->steps()->value('id');
        $this->reserve($request, 3);

        app(RolloverLeaveBalanceAction::class)->execute(2026);

        $request->refresh();
        $this->assertSame(LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER, $request->status);
        $this->assertSame(2026, $request->rollover_source_year);
        $this->assertSame(2027, $request->rollover_target_year);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $request->id)
            ->sum('amount'));
        $this->assertSame($stepId, $request->steps()->value('id'));
        $this->assertSame('active', $request->steps()->value('status'));
        $this->assertSame(0, app(ListPendingLeaveApprovalsAction::class)->execute($approver->id)->total());
        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'leave_request_id' => $request->id,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RELEASED,
            'amount' => -3,
            'dedup_key' => "leave_reservation:{$request->id}:released:rollover:2026",
        ]);

        $notification = SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'cuti.dikembalikan_karena_rollover')
            ->sole();
        $this->assertSame($request->id, $notification->data['leave_request_id']);
        $this->assertSame(2026, $notification->data['source_year']);
        $this->assertSame(2027, $notification->data['target_year']);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
    }

    public function test_rollover_leaves_nonannual_and_terminal_requests_untouched(): void
    {
        $employee = $this->reconciledEmployee();
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
        $sick = RefJenisCuti::query()->where('code', 'sakit')->firstOrFail();
        $terminal = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $annual->id,
            'tanggal_mulai' => '2026-12-22',
            'tanggal_selesai' => '2026-12-22',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Pengajuan final tidak boleh berubah.',
            'status' => 'disetujui',
        ]);
        $nonAnnual = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $sick->id,
            'tanggal_mulai' => '2026-12-23',
            'tanggal_selesai' => '2026-12-23',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Cuti non-tahunan tidak boleh berubah.',
            'status' => 'menunggu_approval',
        ]);
        $otherReducingType = RefJenisCuti::query()->create([
            'nama' => 'Cuti Pengurang Saldo Selain Tahunan',
            'code' => 'pengurang_saldo_lain',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $otherReducing = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $otherReducingType->id,
            'tanggal_mulai' => '2026-12-24',
            'tanggal_selesai' => '2026-12-24',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Jenis selain Tahunan tidak masuk pengembalian rollover.',
            'status' => 'menunggu_approval',
        ]);

        app(RolloverLeaveBalanceAction::class)->execute(2026);

        $this->assertSame('disetujui', $terminal->fresh()->status);
        $this->assertNull($terminal->fresh()->rollover_source_year);
        $this->assertSame('menunggu_approval', $nonAnnual->fresh()->status);
        $this->assertNull($nonAnnual->fresh()->rollover_source_year);
        $this->assertSame('menunggu_approval', $otherReducing->fresh()->status);
        $this->assertNull($otherReducing->fresh()->rollover_source_year);
        $this->assertSame(0, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'cuti.dikembalikan_karena_rollover')
            ->count());
        Queue::assertNothingPushed();
    }

    public function test_rollover_notifies_once_for_multiple_active_requests(): void
    {
        $employee = $this->reconciledEmployee();
        $first = $this->annualRequest($employee, 'menunggu_approval', 2, '2026-12-21');
        $second = $this->annualRequest($employee, 'ditangguhkan', 3, '2026-12-22');
        $this->reserve($first, 2);
        $this->reserve($second, 3);

        app(RolloverLeaveBalanceAction::class)->execute(2026);

        $notification = SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'cuti.dikembalikan_karena_rollover')
            ->sole();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $notification->data['leave_request_ids']);
        $this->assertContains($notification->data['leave_request_id'], [$first->id, $second->id]);
        $this->assertSame(LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER, $first->fresh()->status);
        $this->assertSame(LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER, $second->fresh()->status);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->whereIn('leave_request_id', [$first->id, $second->id])
            ->sum('amount'));
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
    }

    public function test_rollover_locks_request_then_employee_then_balance_and_retry_is_stable(): void
    {
        $employee = $this->reconciledEmployee();
        $request = $this->annualRequest($employee, 'menunggu_approval', 3, '2026-12-21');
        $this->reserve($request, 3);
        $lockOrder = [];

        DB::listen(function ($query) use (&$lockOrder): void {
            $sql = mb_strtolower((string) $query->sql);

            if (! str_contains($sql, 'for update')) {
                return;
            }

            if (str_contains($sql, 'from "leave_requests"')) {
                $lockOrder[] = 'request';
            } elseif (str_contains($sql, 'from "employees"')) {
                $lockOrder[] = 'employee';
            } elseif (str_contains($sql, 'from "leave_balances"')) {
                $lockOrder[] = 'balance';
            }
        });

        $action = app(RolloverLeaveBalanceAction::class);
        $action->execute(2026);

        $this->assertSame(['request', 'employee', 'balance'], array_slice(array_values(array_unique($lockOrder)), 0, 3));
        $beforeRetry = $this->effectCounts($employee);
        $action->execute(2026);
        $this->assertSame($beforeRetry, $this->effectCounts($employee));
    }

    public function test_rollover_gagal_tertutup_tanpa_marker_saat_pembatalan_masih_pending(): void
    {
        $employee = $this->reconciledEmployee();
        $request = $this->annualRequest(
            $employee,
            LeaveRequest::STATUS_CANCELLATION_PENDING,
            3,
            '2026-12-21',
        );
        $this->reserve($request, 3);
        LeaveCancellationRequest::query()->create([
            'leave_request_id' => $request->id,
            'requested_by' => $this->reconciliationActor->id,
            'reason' => 'Pembatalan masih menunggu keputusan saat rollover.',
            'status' => LeaveCancellationRequest::STATUS_PENDING,
            'resume_status' => 'menunggu_approval',
        ]);

        $result = app(RolloverLeaveBalanceAction::class)->execute(2026);

        $this->assertSame(1, $result['attempted']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(LeaveRequest::STATUS_CANCELLATION_PENDING, $request->fresh()->status);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'dedup_key' => "{$employee->id}:2027:rollover_applied",
        ]);
        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
        ]);
        $this->assertSame(3, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $request->id)
            ->sum('amount'));
    }

    public function test_rollover_processes_more_than_one_lazy_chunk(): void
    {
        $employees = collect();

        for ($index = 0; $index < 101; $index++) {
            $employees->push($this->reconciledEmployee());
        }

        app(RolloverLeaveBalanceAction::class)->execute(2026);

        $employeeIds = $employees->pluck('id');
        $this->assertSame(101, LeaveBalance::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('tahun', 2027)
            ->count());
        $this->assertSame(101, LeaveBalanceLedger::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('event_type', LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED)
            ->where('source_year', 2026)
            ->count());
        $this->assertSame(101, AuditLog::query()
            ->where('event', 'LEAVE_ROLLOVER_APPLIED')
            ->where('user_name', 'SIMPEG Scheduler')
            ->count());
    }

    public function test_rollover_preserves_material_predecessor_without_active_reconciliation_set(): void
    {
        $employee = Employee::factory()->create([
            'email' => 'rollover-material-'.fake()->uuid().'@example.test',
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
        ]);
        $employee->appointment()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-ROLLOVER-MATERIAL',
            'tanggal_sk' => '2020-01-01',
        ]);
        LeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'tahun' => 2025,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 6,
            'sisa' => 6,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 6,
            'terpakai_tahun_berjalan' => 6,
            'hangus' => 0,
        ]);
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
        $approvedRequest = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $annual->id,
            'tanggal_mulai' => '2026-06-01',
            'tanggal_selesai' => '2026-06-10',
            'jumlah_hari_kerja' => 8,
            'alasan' => 'Pengajuan legacy yang memakai carry material.',
            'status' => 'disetujui',
        ]);
        LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'leave_request_id' => $approvedRequest->id,
            'usage_year' => 2026,
            'effective_date' => '2026-06-01',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-10',
            'workdays' => 8,
            'administrative_note' => 'Fakta legacy pertama berada pada tahun sumber rollover.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $this->reconciliationActor->id,
        ]);

        $rolloverNow = Carbon::getTestNow();
        Carbon::setTestNow('2026-12-31 10:00:00');

        try {
            app(LeaveBalanceRecalculationService::class)->recalculateForDatabaseUpgrade(
                $employee,
                2026,
                'Membentuk projection sumber dari predecessor material yang sah.',
            );
        } finally {
            Carbon::setTestNow($rolloverNow);
        }

        $sourceBefore = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->sole();
        $this->assertSame(0, DB::table('leave_usage_reconciliation_sets')
            ->where('employee_id', $employee->id)
            ->count());
        $this->assertSame([
            'carry_over' => 6,
            'terpakai' => 8,
            'sisa' => 10,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 10,
        ], $sourceBefore->only([
            'carry_over',
            'terpakai',
            'sisa',
            'sisa_n2',
            'sisa_n1',
            'sisa_tahun_berjalan',
        ]));

        app(RolloverLeaveBalanceAction::class)->execute(2026);

        $this->assertSame($sourceBefore->getAttributes(), $sourceBefore->fresh()->getAttributes());
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'carry_over' => 6,
            'sisa_n2' => 0,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'sisa' => 18,
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'source_year' => 2026,
            'dedup_key' => "{$employee->id}:2027:rollover_applied",
        ]);
    }

    public function test_rollover_isolates_employee_failure_and_clean_retry_is_idempotent(): void
    {
        $employees = collect([
            $this->reconciledEmployee(),
            $this->reconciledEmployee(),
        ])->keyBy('id');
        $orderedEmployeeIds = LeaveBalance::query()
            ->where('tahun', 2026)
            ->whereIn('employee_id', $employees->keys())
            ->orderBy('id')
            ->pluck('employee_id');
        /** @var Employee $failedEmployee */
        $failedEmployee = $employees->get($orderedEmployeeIds->first());
        /** @var Employee $laterEmployee */
        $laterEmployee = $employees->get($orderedEmployeeIds->last());
        $laterRequest = $this->annualRequest($laterEmployee, 'menunggu_approval', 3, '2026-12-21');
        $this->reserve($laterRequest, 3);
        $dispatcher = clone AuditLog::getEventDispatcher();
        $sensitiveMessage = 'Simulasi gagal dengan detail sensitif pegawai.';

        AuditLog::creating(function (AuditLog $audit) use ($failedEmployee, $sensitiveMessage): void {
            if (
                $audit->event === 'LEAVE_ROLLOVER_APPLIED'
                && ($audit->new_values['employee_id'] ?? null) === $failedEmployee->id
            ) {
                throw new RuntimeException($sensitiveMessage);
            }
        });

        try {
            $result = app(RolloverLeaveBalanceAction::class)->execute(2026);
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertSame(2, $result['attempted']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame($failedEmployee->id, $result['failures'][0]['employee_id']);
        $this->assertSame(2026, $result['failures'][0]['source_year']);
        $this->assertSame(RuntimeException::class, $result['failures'][0]['exception_class']);
        $this->assertTrue(Str::isUuid($result['failures'][0]['correlation_id']));
        $this->assertArrayNotHasKey('message', $result['failures'][0]);
        $this->assertStringNotContainsString($sensitiveMessage, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $failedEmployee->id,
            'tahun' => 2027,
        ]);
        $this->assertDatabaseMissing('leave_balance_ledger', [
            'employee_id' => $failedEmployee->id,
            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'source_year' => 2026,
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $laterEmployee->id,
            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'source_year' => 2026,
        ]);
        $this->assertSame(LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER, $laterRequest->fresh()->status);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $laterRequest->id)
            ->sum('amount'));

        $retry = app(RolloverLeaveBalanceAction::class)->execute(2026);

        $this->assertSame(2, $retry['attempted']);
        $this->assertSame(2, $retry['processed']);
        $this->assertSame(0, $retry['failed']);
        $this->assertSame(1, LeaveBalanceLedger::query()
            ->where('employee_id', $failedEmployee->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED)
            ->where('source_year', 2026)
            ->count());
        $this->assertSame(1, LeaveBalanceLedger::query()
            ->where('employee_id', $laterEmployee->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED)
            ->where('source_year', 2026)
            ->count());
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
    }

    public function test_command_explicit_year_uses_fact_projection_and_scheduler_contract(): void
    {
        $employee = $this->reconciledEmployee([2024 => 0, 2025 => 0, 2026 => 3]);

        $this->artisan('cuti:rollover 2026')
            ->expectsOutput('Rollover saldo cuti tahun 2026 ke 2027 selesai.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'sisa_n2' => 0,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'sisa' => 18,
            'hangus' => 15,
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED,
            'source_year' => 2027,
            'created_by' => null,
        ]);

        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'cuti:rollover'));
        $this->assertNotNull($event);
        $this->assertSame('5 0 1 1 *', $event->expression);
        $this->assertSame(config('app.timezone'), $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(120, $event->expiresAt);
    }

    public function test_command_without_argument_closes_previous_year(): void
    {
        $employee = $this->reconciledEmployee();

        $this->artisan('cuti:rollover')
            ->expectsOutput('Rollover saldo cuti tahun 2026 ke 2027 selesai.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('leave_balance_ledger', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'source_year' => 2026,
            'dedup_key' => "{$employee->id}:2027:rollover_applied",
        ]);
    }

    public function test_command_logs_sanitized_failures_and_returns_nonzero(): void
    {
        $failure = [
            'employee_id' => (string) Str::uuid(),
            'source_year' => 2026,
            'exception_class' => RuntimeException::class,
            'correlation_id' => (string) Str::uuid(),
        ];
        $this->mock(
            RolloverLeaveBalanceAction::class,
            function (MockInterface $mock) use ($failure): void {
                /** @var Expectation $expectation */
                $expectation = $mock->shouldReceive('execute');
                $expectation->once()
                    ->with(2026)
                    ->andReturn([
                        'attempted' => 2,
                        'processed' => 1,
                        'failed' => 1,
                        'failures' => [$failure],
                    ]);
            },
        );
        Log::shouldReceive('error')
            ->once()
            ->with('Rollover saldo cuti pegawai gagal.', $failure);

        $this->artisan('cuti:rollover 2026')
            ->expectsOutput('Rollover saldo cuti tahun 2026 ke 2027 selesai.')
            ->expectsOutput('Berhasil: 1, gagal: 1.')
            ->assertExitCode(1);
    }

    public function test_command_rejects_invalid_year_before_balance_query(): void
    {
        $balanceQueries = 0;
        DB::listen(function ($query) use (&$balanceQueries): void {
            if (str_contains(mb_strtolower((string) $query->sql), 'leave_balances')) {
                $balanceQueries++;
            }
        });

        $this->artisan('cuti:rollover bukan-tahun')
            ->expectsOutput('Tahun sumber rollover harus berupa tahun 4 digit, contoh: 2026.')
            ->assertExitCode(1);

        $this->assertSame(0, $balanceQueries);
    }

    /** @return array<string, array{string}> */
    public static function activeAnnualLeaveStatusProvider(): array
    {
        return [
            'menunggu approval' => ['menunggu_approval'],
            'ditangguhkan' => ['ditangguhkan'],
        ];
    }

    /** @param array<int, int> $usage */
    private function reconciledEmployee(array $usage = [2024 => 0, 2025 => 0, 2026 => 0]): Employee
    {
        $employee = Employee::factory()->create([
            'email' => 'rollover-'.fake()->uuid().'@example.test',
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
        ]);
        $employee->appointment()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-ROLLOVER-FACT',
            'tanggal_sk' => '2020-01-01',
        ]);
        $rolloverNow = Carbon::getTestNow();
        Carbon::setTestNow('2026-12-31 10:00:00');

        try {
            app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
                $employee,
                2026,
                $usage,
                Carbon::parse('2026-12-31 10:00:00'),
                'Fixture rekonsiliasi untuk orkestrasi rollover.',
                $this->reconciliationActor,
            );
            $this->assertDatabaseMissing('leave_balances', [
                'employee_id' => $employee->id,
                'tahun' => 2027,
            ]);
        } finally {
            Carbon::setTestNow($rolloverNow);
        }

        return $employee;
    }

    private function annualRequest(Employee $employee, string $status, int $days, string $start): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'tahunan')->value('id'),
            'tanggal_mulai' => $start,
            'tanggal_selesai' => $start,
            'jumlah_hari_kerja' => $days,
            'alasan' => 'Pengajuan aktif menjelang rollover.',
            'status' => $status,
        ]);
    }

    private function reserve(LeaveRequest $request, int $days): void
    {
        $balance = LeaveBalance::query()
            ->where('employee_id', $request->employee_id)
            ->where('tahun', 2026)
            ->sole();
        LeaveBalanceReservationEvent::query()->create([
            'employee_id' => $request->employee_id,
            'leave_request_id' => $request->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => $days,
            'reason' => 'Fixture reservasi aktif append-only.',
            'dedup_key' => "leave_reservation:{$request->id}:reserved",
            'metadata' => ['requested_days' => $days],
            'created_by' => $this->reconciliationActor->id,
            'occurred_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function effectCounts(Employee $employee): array
    {
        $queue = Queue::getFacadeRoot();

        if (! $queue instanceof QueueFake) {
            throw new RuntimeException('Queue fake wajib aktif saat menghitung efek rollover.');
        }

        return [
            'balances' => LeaveBalance::query()->where('employee_id', $employee->id)->count(),
            'ledger' => LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count(),
            'reservations' => LeaveBalanceReservationEvent::query()->where('employee_id', $employee->id)->count(),
            'audits' => AuditLog::query()->count(),
            'notifications' => SimpegNotification::query()->where('user_id', $employee->id)->count(),
            'queued_emails' => $queue->pushed(SendSimpegNotificationEmailJob::class)->count(),
        ];
    }
}
