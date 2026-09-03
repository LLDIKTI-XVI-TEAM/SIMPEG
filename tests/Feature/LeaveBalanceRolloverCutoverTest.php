<?php

namespace Tests\Feature;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\RolloverLeaveBalanceAction;
use App\Jobs\SendSimpegNotificationEmailJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveUsageReconciliationMembership;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\Cuti\LeaveUsageReconciliationService;
use App\Services\Cuti\LeaveUsageRecordService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class LeaveBalanceRolloverCutoverTest extends TestCase
{
    use RefreshDatabase;

    private QueueManager $actualQueueManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        Carbon::setTestNow('2027-01-01 00:05:00');
        Storage::fake('local');
        $this->actualQueueManager = app('queue');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rollover_replays_facts_with_exact_projection_and_system_marker(): void
    {
        $fixture = $this->reconciledEmployee([2024 => 0, 2025 => 0, 2026 => 3]);
        User::factory()->create(['role' => 'super_admin']);
        User::factory()->create(['role' => 'admin_kepegawaian']);
        $legacyBefore = $this->legacyEventCount($fixture['employee']);
        $sourceFacts = $this->factAndSetSnapshot($fixture['employee']);

        app(RolloverLeaveBalanceAction::class)->execute(2026);

        $target = $this->assertProjection($fixture['employee'], 2027, [
            'jatah_awal' => 12,
            'carry_over' => 6,
            'terpakai' => 0,
            'sisa' => 18,
            'sisa_n2' => 0,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 15,
        ]);
        $marker = LeaveBalanceLedger::query()
            ->where('employee_id', $fixture['employee']->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED)
            ->sole();
        $this->assertSame(2026, $marker->source_year);
        $this->assertSame(2027, $marker->tahun);
        $this->assertSame($target->id, $marker->leave_balance_id);
        $this->assertNull($marker->created_by);
        $this->assertSame("{$fixture['employee']->id}:2027:rollover_applied", $marker->dedup_key);
        $this->assertSame('SIMPEG Scheduler', $marker->metadata['system_actor'] ?? null);

        $carry = LeaveBalanceLedger::query()
            ->where('employee_id', $fixture['employee']->id)
            ->where('tahun', 2027)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED)
            ->sole();
        $this->assertSame(6, $carry->amount);
        $this->assertSame(2026, $carry->source_year);
        $this->assertSame('SIMPEG Scheduler', $carry->metadata['system_actor'] ?? null);

        $expiry = LeaveBalanceLedger::query()
            ->where('employee_id', $fixture['employee']->id)
            ->where('tahun', 2027)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED)
            ->sole();
        $this->assertSame(15, $expiry->metadata['expired_days'] ?? null);
        $this->assertNull($expiry->created_by);

        $recalculation = LeaveBalanceLedger::query()
            ->where('employee_id', $fixture['employee']->id)
            ->where('tahun', 2027)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->sole();
        $this->assertNull($recalculation->created_by);
        $this->assertSame(18, $recalculation->metadata['after']['sisa'] ?? null);

        $markerAudit = AuditLog::query()
            ->where('event', 'LEAVE_ROLLOVER_APPLIED')
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $target->id)
            ->sole();
        $this->assertSystemAudit($markerAudit);
        $this->assertSame('rollover_applied', $markerAudit->new_values['operation'] ?? null);
        $this->assertSame(2026, $markerAudit->new_values['source_year'] ?? null);
        $this->assertSame(2027, $markerAudit->new_values['target_year'] ?? null);

        $replayAudit = AuditLog::query()
            ->where('event', 'UPDATE')
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $target->id)
            ->where('user_name', 'SIMPEG Scheduler')
            ->sole();
        $this->assertSystemAudit($replayAudit);
        $this->assertSame(18, $replayAudit->new_values['after']['sisa'] ?? null);
        $this->assertSame($sourceFacts, $this->factAndSetSnapshot($fixture['employee']));
        $this->assertSame($legacyBefore, $this->legacyEventCount($fixture['employee']));
    }

    public function test_rollover_returns_active_request_and_retry_deduplicates_all_effects(): void
    {
        $fixture = $this->reconciledEmployee();
        $request = $this->activeAnnualRequest($fixture['employee'], 3);
        $source = $this->balance($fixture['employee'], 2026);
        $this->reserve($request, $source, $fixture['actor'], 3);
        $rollover = app(RolloverLeaveBalanceAction::class);

        $rollover->execute(2026);

        $request->refresh();
        $this->assertSame(LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER, $request->status);
        $this->assertSame(2026, $request->rollover_source_year);
        $this->assertSame(2027, $request->rollover_target_year);
        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $request->id)
            ->sum('amount'));
        $release = LeaveBalanceReservationEvent::query()
            ->where('dedup_key', "leave_reservation:{$request->id}:released:rollover:2026")
            ->sole();
        $this->assertSame(-3, $release->amount);
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $fixture['employee']->id)
            ->where('type', 'cuti.dikembalikan_karena_rollover')
            ->count());
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);

        foreach (AuditLog::query()
            ->whereIn('auditable_id', [$request->id, $release->id])
            ->whereIn('event', ['UPDATE', 'LEAVE_BALANCE_RESERVATION_RELEASED'])
            ->get() as $audit) {
            $this->assertSystemAudit($audit);
        }

        $beforeRetry = $this->effectSnapshot($fixture['employee'], $request);
        $rollover->execute(2026);

        $this->assertSame($beforeRetry, $this->effectSnapshot($fixture['employee'], $request));
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
    }

    public function test_failure_of_final_marker_audit_rolls_back_complete_transient_state(): void
    {
        $fixture = $this->reconciledEmployee();
        $request = $this->activeAnnualRequest($fixture['employee'], 3);
        $source = $this->balance($fixture['employee'], 2026);
        $this->reserve($request, $source, $fixture['actor'], 3);
        $before = $this->effectSnapshot($fixture['employee'], $request);
        $sentinelFacts = $this->factAndSetSnapshot($fixture['employee']);
        $dispatcher = clone AuditLog::getEventDispatcher();
        $queueFake = Queue::getFacadeRoot();
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
        ]);
        $this->actualQueueManager->setDefaultDriver('database');
        Queue::swap($this->actualQueueManager);
        $observed = [];

        AuditLog::creating(function (AuditLog $audit) use ($fixture, $request, $sentinelFacts, &$observed): void {
            if ($audit->event !== 'LEAVE_ROLLOVER_APPLIED') {
                return;
            }

            $target = $this->balance($fixture['employee'], 2027);
            $release = LeaveBalanceReservationEvent::query()
                ->where('dedup_key', "leave_reservation:{$request->id}:released:rollover:2026")
                ->sole();
            $observed = [
                'request' => $request->fresh()->only(['status', 'rollover_source_year', 'rollover_target_year']),
                'reservation_total' => (int) LeaveBalanceReservationEvent::query()->where('leave_request_id', $request->id)->sum('amount'),
                'target' => $this->projectionSnapshot($target),
                'marker' => $this->ledgerEventCount($fixture['employee'], 2027, LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED),
                'carry' => $this->ledgerEventCount($fixture['employee'], 2027, LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED),
                'recalculation' => $this->ledgerEventCount($fixture['employee'], 2027, LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED),
                'expiry' => $this->ledgerEventCount($fixture['employee'], 2027, LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED),
                'request_audit' => AuditLog::query()->where('auditable_id', $request->id)->where('event', 'UPDATE')->count(),
                'release_audit' => AuditLog::query()->where('auditable_id', $release->id)->where('event', 'LEAVE_BALANCE_RESERVATION_RELEASED')->count(),
                'replay_audit' => AuditLog::query()->where('auditable_id', $target->id)->where('event', 'UPDATE')->where('user_name', 'SIMPEG Scheduler')->count(),
                'marker_audit' => AuditLog::query()->where('auditable_id', $target->id)->where('event', 'LEAVE_ROLLOVER_APPLIED')->count(),
                'notification' => SimpegNotification::query()->where('user_id', $fixture['employee']->id)->where('type', 'cuti.dikembalikan_karena_rollover')->count(),
                'queued_email' => DB::table('jobs')->count(),
                'facts_unchanged' => $sentinelFacts === $this->factAndSetSnapshot($fixture['employee']),
            ];

            throw new RuntimeException('Simulasi audit marker rollover gagal.');
        });

        try {
            $result = app(RolloverLeaveBalanceAction::class)->execute(2026);
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
            Queue::swap($queueFake);
        }

        $this->assertSame(1, $result['attempted']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame($fixture['employee']->id, $result['failures'][0]['employee_id']);
        $this->assertSame(RuntimeException::class, $result['failures'][0]['exception_class']);
        $this->assertArrayNotHasKey('message', $result['failures'][0]);

        $this->assertSame([
            'request' => ['status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER, 'rollover_source_year' => 2026, 'rollover_target_year' => 2027],
            'reservation_total' => 0,
            'target' => [
                'tahun' => 2027,
                'jatah_awal' => 12,
                'carry_over' => 12,
                'terpakai' => 0,
                'sisa' => 24,
                'sisa_n2' => 6,
                'sisa_n1' => 6,
                'sisa_tahun_berjalan' => 12,
                'terpakai_tahun_berjalan' => 0,
                'hangus' => 12,
            ],
            'marker' => 1,
            'carry' => 1,
            'recalculation' => 1,
            'expiry' => 1,
            'request_audit' => 1,
            'release_audit' => 1,
            'replay_audit' => 1,
            'marker_audit' => 0,
            'notification' => 1,
            'queued_email' => 0,
            'facts_unchanged' => true,
        ], $observed);
        $this->assertSame($before, $this->effectSnapshot($fixture['employee'], $request));
        $this->assertSame($sentinelFacts, $this->factAndSetSnapshot($fixture['employee']));
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_notification_failure_rolls_back_rollover_and_clean_retry_is_idempotent(): void
    {
        $fixture = $this->reconciledEmployee();
        $request = $this->activeAnnualRequest($fixture['employee'], 3);
        $this->reserve($request, $this->balance($fixture['employee'], 2026), $fixture['actor'], 3);
        $before = $this->effectSnapshot($fixture['employee'], $request);
        $dispatcher = clone SimpegNotification::getEventDispatcher();

        SimpegNotification::creating(function (SimpegNotification $notification): void {
            if ($notification->type === 'cuti.dikembalikan_karena_rollover') {
                throw new RuntimeException('Simulasi notifikasi rollover gagal.');
            }
        });

        try {
            $result = app(RolloverLeaveBalanceAction::class)->execute(2026);
        } finally {
            SimpegNotification::setEventDispatcher($dispatcher);
        }

        $this->assertSame(1, $result['attempted']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame($fixture['employee']->id, $result['failures'][0]['employee_id']);
        $this->assertSame(RuntimeException::class, $result['failures'][0]['exception_class']);
        $this->assertArrayNotHasKey('message', $result['failures'][0]);

        $this->assertSame($before, $this->effectSnapshot($fixture['employee'], $request));
        Queue::assertNothingPushed();

        $rollover = app(RolloverLeaveBalanceAction::class);
        $rollover->execute(2026);
        $afterSuccess = $this->effectSnapshot($fixture['employee'], $request);
        $rollover->execute(2026);

        $this->assertSame($afterSuccess, $this->effectSnapshot($fixture['employee'], $request));
        $this->assertSame(1, SimpegNotification::query()->where('user_id', $fixture['employee']->id)->where('type', 'cuti.dikembalikan_karena_rollover')->count());
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
    }

    public function test_duty_postponement_is_exact_and_expires_after_one_rollover_year(): void
    {
        $fixture = $this->reconciledEmployee([2024 => 1, 2025 => 1, 2026 => 14]);
        $dutyRequest = LeaveRequest::query()->create([
            'employee_id' => $fixture['employee']->id,
            'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'tahunan')->value('id'),
            'tanggal_mulai' => '2026-12-21',
            'tanggal_selesai' => '2026-12-24',
            'jumlah_hari_kerja' => 4,
            'alasan' => 'Hak ditunda karena penugasan dinas.',
            'status' => LeaveRequest::STATUS_DUTY_POSTPONED,
        ]);
        $duty = app(LeaveBalanceService::class)->recordDutyPostponement($dutyRequest, $fixture['actor'], 'Penugasan instansi akhir tahun.');
        $this->assertSame(['n2' => 0, 'n1' => 0, 'current' => 4], $duty->metadata['protected_allocations']);

        $rollover = app(RolloverLeaveBalanceAction::class);
        $rollover->execute(2026);

        $target2027 = $this->assertProjection($fixture['employee'], 2027, [
            'jatah_awal' => 12,
            'carry_over' => 4,
            'terpakai' => 0,
            'sisa' => 16,
            'sisa_n2' => 0,
            'sisa_n1' => 4,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);
        $this->assertSame(1, $this->ledgerEventCount(
            $fixture['employee'],
            2027,
            LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED,
        ));
        $this->assertSystemAudit(AuditLog::query()
            ->where('event', 'UPDATE')
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $target2027->id)
            ->sole());
        $this->assertSame('SIMPEG Scheduler', LeaveBalanceLedger::query()
            ->where('dedup_key', "{$fixture['employee']->id}:2027:rollover_applied")
            ->sole()
            ->metadata['system_actor'] ?? null);
        $carry2027 = LeaveBalanceLedger::query()
            ->where('employee_id', $fixture['employee']->id)
            ->where('tahun', 2027)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED)
            ->sole();
        $this->assertSame(4, $carry2027->metadata['duty_postponed_carried'] ?? null);

        $secondDutyRequest = LeaveRequest::query()->create([
            'employee_id' => $fixture['employee']->id,
            'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'tahunan')->value('id'),
            'tanggal_mulai' => '2027-12-01',
            'tanggal_selesai' => '2027-12-20',
            'jumlah_hari_kerja' => 14,
            'alasan' => 'Penangguhan berikutnya tidak boleh melindungi ulang hak N-1 yang akan kedaluwarsa.',
            'status' => LeaveRequest::STATUS_DUTY_POSTPONED,
        ]);

        try {
            app(LeaveBalanceService::class)->recordDutyPostponement(
                $secondDutyRequest,
                $fixture['actor'],
                'Penugasan dinas tahun berikutnya.',
            );
            $this->fail('Hak penangguhan N-1 yang akan kedaluwarsa tidak boleh dilindungi ulang.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('jumlah_hari', $exception->errors());
        }

        Carbon::setTestNow('2028-01-01 00:05:00');
        $rollover->execute(2027);

        $target2028 = $this->assertProjection($fixture['employee'], 2028, [
            'jatah_awal' => 12,
            'carry_over' => 6,
            'terpakai' => 0,
            'sisa' => 18,
            'sisa_n2' => 0,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 10,
        ]);
        $this->assertSame(1, $this->ledgerEventCount(
            $fixture['employee'],
            2028,
            LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED,
        ));
        $this->assertSystemAudit(AuditLog::query()
            ->where('event', 'UPDATE')
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $target2028->id)
            ->sole());
        $expiry = LeaveBalanceLedger::query()->where('employee_id', $fixture['employee']->id)->where('tahun', 2028)->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED)->sole();
        $this->assertSame(10, $expiry->metadata['expired_days'] ?? null);
        $this->assertSame(1, LeaveBalanceLedger::query()->where('dedup_key', "{$fixture['employee']->id}:2027:rollover_applied")->count());
        $this->assertSame(1, LeaveBalanceLedger::query()->where('dedup_key', "{$fixture['employee']->id}:2028:rollover_applied")->count());
    }

    public function test_active_annual_usage_fact_blocks_rule_five_without_legacy_deduction_event(): void
    {
        $fixture = $this->reconciledEmployee([2024 => 0, 2025 => 0, 2026 => 3]);

        try {
            app(LeaveBalanceService::class)->assertCutiBesarCanBeFinallyApproved($fixture['employee'], 2026);
            $this->fail('Cuti Besar wajib ditolak ketika fakta pemakaian Cuti Tahunan aktif sudah ada.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('sudah digunakan', $exception->errors()['status'][0]);
        }

        $this->assertSame(3, LeaveUsageRecord::query()->where('employee_id', $fixture['employee']->id)->where('usage_year', 2026)->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)->where('workdays', '>', 0)->sum('workdays'));
        $this->assertSame(0, $this->legacyEventCount($fixture['employee']));
    }

    public function test_approved_request_cuti_besar_fact_is_rule_five_source_and_preserves_source_buckets(): void
    {
        $fixture = $this->reconciledEmployee();
        $sourceBefore = $this->projectionSnapshot($this->balance($fixture['employee'], 2026));
        $request = LeaveRequest::query()->create([
            'employee_id' => $fixture['employee']->id,
            'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'besar')->value('id'),
            'tanggal_mulai' => '2026-06-01',
            'tanggal_selesai' => '2026-06-30',
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar final yang menjadi fakta Rule 5.',
            'status' => 'disetujui',
        ]);

        $fact = app(LeaveUsageRecordService::class)->recordApprovedRequest($request, $fixture['actor']);

        $this->assertSame(LeaveUsageRecord::SOURCE_APPROVED_REQUEST, $fact->source_type);
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $fact->record_status);
        $this->assertSame('besar', $fact->jenisCuti()->value('code'));
        $this->assertRuleFiveTarget($fixture['employee']);
        $this->assertSame($sourceBefore, $this->projectionSnapshot($this->balance($fixture['employee'], 2026)));

        // Lifecycle request tidak boleh kembali menjadi sumber Rule 5 setelah fakta final tercatat.
        $request->forceFill(['status' => 'tidak_disetujui'])->save();
        $this->assertSame(0, app(LeaveBalanceService::class)->availableFor($fixture['employee'], 2026));
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $fixture['employee'],
            2026,
            $fixture['actor'],
            'Pastikan replay hanya membaca fakta Cuti Besar aktif.',
        );
        $this->assertRuleFiveTarget($fixture['employee']);

        app(RolloverLeaveBalanceAction::class)->execute(2026);

        $this->assertRuleFiveTarget($fixture['employee']);
        $this->assertSame($sourceBefore, $this->projectionSnapshot($this->balance($fixture['employee'], 2026)));
        $this->assertRuleFiveLedgerAndAudit($fixture['employee'], $fixture['actor']);
    }

    public function test_manual_external_cuti_besar_fact_suppresses_only_current_carry(): void
    {
        $fixture = $this->reconciledEmployee();
        $sourceBefore = $this->projectionSnapshot($this->balance($fixture['employee'], 2026));

        $fact = $this->recordManualFact(
            $fixture['employee'],
            $fixture['actor'],
            RefJenisCuti::query()->where('code', 'besar')->firstOrFail(),
            'Cuti Besar eksternal yang sudah final.',
        );

        $this->assertSame(LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL, $fact->source_type);
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $fact->record_status);
        $this->assertSame(0, app(LeaveBalanceService::class)->availableFor($fixture['employee'], 2026));
        $this->assertRuleFiveTarget($fixture['employee']);
        $this->assertSame($sourceBefore, $this->projectionSnapshot($this->balance($fixture['employee'], 2026)));

        app(RolloverLeaveBalanceAction::class)->execute(2026);

        $this->assertRuleFiveTarget($fixture['employee']);
        $this->assertSame($sourceBefore, $this->projectionSnapshot($this->balance($fixture['employee'], 2026)));
        $this->assertRuleFiveLedgerAndAudit($fixture['employee'], $fixture['actor']);
    }

    public function test_manual_cuti_besar_correction_and_cancellation_replay_rule_five_projection(): void
    {
        $fixture = $this->reconciledEmployee();
        $records = app(LeaveUsageRecordService::class);
        $large = RefJenisCuti::query()->where('code', 'besar')->firstOrFail();
        $sick = RefJenisCuti::query()->where('code', 'sakit')->firstOrFail();
        $sourceBefore = $this->projectionSnapshot($this->balance($fixture['employee'], 2026));
        $current = $this->recordManualFact($fixture['employee'], $fixture['actor'], $large, 'Cuti Besar eksternal awal.');

        $this->assertRuleFiveTarget($fixture['employee']);
        $this->assertSame(1, $this->targetRecalculationCount($fixture['employee']));

        $current = $records->replace(
            $current,
            ['leave_type_id' => $sick->id, 'administrative_note' => 'Dikoreksi menjadi Cuti Sakit.'],
            'Jenis cuti pada bukti lama salah.',
            $fixture['actor'],
            approvalSteps: $this->validManualApprovalStepData(),
        );
        $this->assertNormalTarget($fixture['employee']);
        $this->assertSame(2, $this->targetRecalculationCount($fixture['employee']));

        $current = $records->replace(
            $current,
            ['leave_type_id' => $large->id, 'administrative_note' => 'Dikoreksi kembali menjadi Cuti Besar.'],
            'Verifikasi dokumen menetapkan kembali Cuti Besar.',
            $fixture['actor'],
            approvalSteps: $this->validManualApprovalStepData(),
        );
        $this->assertRuleFiveTarget($fixture['employee']);
        $this->assertSame(3, $this->targetRecalculationCount($fixture['employee']));

        $records->cancel($current, 'Keputusan eksternal dibatalkan.', $fixture['actor']);
        $this->assertNormalTarget($fixture['employee']);
        $this->assertSame(4, $this->targetRecalculationCount($fixture['employee']));
        $this->assertSame(4, AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $this->balance($fixture['employee'], 2027)->id)
            ->where('user_id', $fixture['actor']->id)
            ->count());
        $this->assertSame($sourceBefore, $this->projectionSnapshot($this->balance($fixture['employee'], 2026)));

        app(RolloverLeaveBalanceAction::class)->execute(2026);
        $this->assertNormalTarget($fixture['employee']);
        $this->assertSame(1, LeaveBalanceLedger::query()
            ->where('dedup_key', "{$fixture['employee']->id}:2027:rollover_applied")
            ->count());
    }

    public function test_cuti_besar_fact_lifecycle_without_annual_snapshot_does_not_create_projection(): void
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $records = app(LeaveUsageRecordService::class);
        $large = RefJenisCuti::query()->where('code', 'besar')->firstOrFail();
        $sick = RefJenisCuti::query()->where('code', 'sakit')->firstOrFail();
        $current = $this->recordManualFact($employee, $actor, $large, 'Fakta besar tanpa snapshot tahunan.');
        $current = $records->replace(
            $current,
            ['leave_type_id' => $sick->id],
            'Koreksi non-tahunan.',
            $actor,
            approvalSteps: $this->validManualApprovalStepData(),
        );
        $current = $records->replace(
            $current,
            ['leave_type_id' => $large->id],
            'Kembali menjadi Cuti Besar.',
            $actor,
            approvalSteps: $this->validManualApprovalStepData(),
        );
        $records->cancel($current, 'Batalkan fakta tanpa snapshot.', $actor);

        $this->assertSame(3, LeaveUsageRecord::query()->where('employee_id', $employee->id)->count());
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
        $this->assertSame(0, LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count());
        $this->assertSame(0, app(LeaveBalanceService::class)->availableFor($employee, 2026));
        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id]);
    }

    public function test_late_final_annual_approval_after_marker_is_rejected_without_any_mutation(): void
    {
        $fixture = $this->reconciledEmployee();
        app(RolloverLeaveBalanceAction::class)->execute(2026);

        $this->assertLateApprovalRejected($fixture['employee'], $this->pendingFinalApproval($fixture['employee'], 'tahunan'));
    }

    public function test_late_final_cuti_besar_approval_after_marker_is_rejected_without_any_mutation(): void
    {
        $fixture = $this->reconciledEmployee();
        app(RolloverLeaveBalanceAction::class)->execute(2026);

        $this->assertLateApprovalRejected($fixture['employee'], $this->pendingFinalApproval($fixture['employee'], 'besar'));
    }

    /**
     * @param  array<int, int>  $usage
     * @return array{employee: Employee, actor: User}
     */
    private function reconciledEmployee(array $usage = [2024 => 0, 2025 => 0, 2026 => 0]): array
    {
        $employee = Employee::factory()->create([
            'email' => 'rollover-'.fake()->uuid().'@example.test',
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
        ]);
        $employee->appointment()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-ROLLOVER-CUTOVER',
            'tanggal_sk' => '2020-01-01',
        ]);
        $actor = User::factory()->adminKepegawaian()->create();
        $rolloverNow = Carbon::getTestNow();
        Carbon::setTestNow('2026-12-31 10:00:00');

        try {
            app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
                $employee,
                2026,
                $usage,
                Carbon::parse('2026-12-31 10:00:00'),
                'Rekonsiliasi fixture rollover berbasis fakta.',
                $actor,
            );
            $this->assertDatabaseMissing('leave_balances', ['employee_id' => $employee->id, 'tahun' => 2027]);
        } finally {
            Carbon::setTestNow($rolloverNow);
        }

        return compact('employee', 'actor');
    }

    private function activeAnnualRequest(Employee $employee, int $workdays): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'tahunan')->value('id'),
            'tanggal_mulai' => '2026-12-21',
            'tanggal_selesai' => '2026-12-23',
            'jumlah_hari_kerja' => $workdays,
            'alasan' => 'Pengajuan aktif menjelang rollover.',
            'status' => 'menunggu_approval',
        ]);
    }

    /** @return array{request: LeaveRequest, approver: Employee, user: User} */
    private function pendingFinalApproval(Employee $employee, string $typeCode): array
    {
        $approver = Employee::factory()->create();
        $user = User::factory()->create(['role' => 'pimpinan', 'employee_id' => $approver->id]);
        $type = RefJenisCuti::query()->where('code', $typeCode)->firstOrFail();
        $request = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $type->id,
            'tanggal_mulai' => $typeCode === 'besar' ? '2026-08-03' : '2026-12-21',
            'tanggal_selesai' => $typeCode === 'besar' ? '2026-08-31' : '2026-12-23',
            'jumlah_hari_kerja' => $typeCode === 'besar' ? 20 : 3,
            'alasan' => 'Pengajuan sumber terlambat setelah rollover.',
            'status' => 'menunggu_approval',
        ]);
        LeaveRequestStep::query()->create([
            'leave_request_id' => $request->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        return compact('request', 'approver', 'user');
    }

    /** @param array{request: LeaveRequest, approver: Employee, user: User} $approval */
    private function assertLateApprovalRejected(Employee $employee, array $approval): void
    {
        $before = $this->approvalSnapshot($employee, $approval['request']);

        try {
            app(ApproveLeaveAction::class)->execute(
                $approval['request'],
                $approval['approver'],
                $approval['request']->steps()->where('status', 'active')->valueOrFail('id'),
                'Keputusan final terlambat.',
                $this->approvalRequest($approval['user']),
            );
            $this->fail('Persetujuan final tahun sumber wajib ditolak setelah marker rollover ada.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('sudah ditutup oleh rollover', $exception->errors()['status'][0]);
        }

        $this->assertSame($before, $this->approvalSnapshot($employee, $approval['request']));
        Queue::assertNothingPushed();
    }

    private function approvalRequest(User $actor): Request
    {
        $request = Request::create('/cuti/approval', 'POST', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.50',
            'HTTP_USER_AGENT' => 'SIMPEG-Rollover-Cutover-Test/1.0',
        ]);
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }

    private function reserve(LeaveRequest $request, LeaveBalance $balance, User $actor, int $days): void
    {
        LeaveBalanceReservationEvent::query()->create([
            'employee_id' => $request->employee_id,
            'leave_request_id' => $request->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => $days,
            'reason' => 'Fixture reservasi rollover.',
            'dedup_key' => "leave_reservation:{$request->id}:reserved",
            'metadata' => ['requested_days' => $days],
            'created_by' => $actor->id,
            'occurred_at' => now(),
        ]);
    }

    private function recordManualFact(
        Employee $employee,
        User $actor,
        RefJenisCuti $leaveType,
        string $note,
    ): LeaveUsageRecord {
        return app(LeaveUsageRecordService::class)->recordManual(
            $employee,
            $leaveType,
            '2026-06-01',
            '2026-06-30',
            20,
            $note,
            null,
            'FIXTURE/2026/ROLLOVER',
            $this->validManualApprovalStepData(),
            $actor,
            ['original_name' => 'bukti.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1024],
        );
    }

    private function assertRuleFiveTarget(Employee $employee): void
    {
        $this->assertProjection($employee, 2027, [
            'jatah_awal' => 12,
            'carry_over' => 6,
            'terpakai' => 0,
            'sisa' => 18,
            'sisa_n2' => 6,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 18,
        ]);
    }

    private function assertNormalTarget(Employee $employee): void
    {
        $this->assertProjection($employee, 2027, [
            'jatah_awal' => 12,
            'carry_over' => 12,
            'terpakai' => 0,
            'sisa' => 24,
            'sisa_n2' => 6,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 12,
        ]);
    }

    private function targetRecalculationCount(Employee $employee): int
    {
        return LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2027)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count();
    }

    private function assertRuleFiveLedgerAndAudit(Employee $employee, User $actor): void
    {
        $target = $this->balance($employee, 2027);
        $recalculation = LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2027)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->firstOrFail();
        $this->assertSame($actor->id, $recalculation->created_by);
        $this->assertSame(18, $recalculation->metadata['after']['sisa'] ?? null);
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', 'LeaveBalance')
            ->where('auditable_id', $target->id)
            ->where('user_id', $actor->id)
            ->count());
        $markerAudit = AuditLog::query()
            ->where('event', 'LEAVE_ROLLOVER_APPLIED')
            ->where('auditable_id', $target->id)
            ->sole();
        $this->assertSystemAudit($markerAudit);
        $this->assertSame(6, LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', 2027)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED)
            ->sole()
            ->amount);
    }

    private function balance(Employee $employee, int $year): LeaveBalance
    {
        return LeaveBalance::query()->where('employee_id', $employee->id)->where('tahun', $year)->sole();
    }

    /** @param array<string, int> $expected */
    private function assertProjection(Employee $employee, int $year, array $expected): LeaveBalance
    {
        $balance = $this->balance($employee, $year);
        $this->assertSame(['tahun' => $year, ...$expected], $this->projectionSnapshot($balance));

        return $balance;
    }

    /** @return array<string, int> */
    private function projectionSnapshot(LeaveBalance $balance): array
    {
        return collect($balance->only([
            'tahun', 'jatah_awal', 'carry_over', 'terpakai', 'sisa',
            'sisa_n2', 'sisa_n1', 'sisa_tahun_berjalan',
            'terpakai_tahun_berjalan', 'hangus',
        ]))->map(fn (mixed $value): int => (int) $value)->all();
    }

    private function assertSystemAudit(AuditLog $audit): void
    {
        $this->assertNull($audit->user_id);
        $this->assertSame('SIMPEG Scheduler', $audit->user_name);
        $this->assertSame('system', $audit->new_values['actor_type'] ?? null);
    }

    private function ledgerEventCount(Employee $employee, int $year, string $eventType): int
    {
        return LeaveBalanceLedger::query()->where('employee_id', $employee->id)->where('tahun', $year)->where('event_type', $eventType)->count();
    }

    private function legacyEventCount(Employee $employee): int
    {
        return LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->whereIn('event_type', [LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET, LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT, LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED])
            ->count();
    }

    /** @return array<string, mixed> */
    private function factAndSetSnapshot(Employee $employee): array
    {
        $setIds = LeaveUsageReconciliationSet::query()->where('employee_id', $employee->id)->orderBy('id')->pluck('id');

        return [
            'sets' => LeaveUsageReconciliationSet::query()->whereIn('id', $setIds)->orderBy('id')->get()->toArray(),
            'facts' => LeaveUsageRecord::query()->where('employee_id', $employee->id)->orderBy('id')->get()->toArray(),
            'memberships' => LeaveUsageReconciliationMembership::query()->whereIn('reconciliation_set_id', $setIds)->orderBy('id')->get()->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function effectSnapshot(Employee $employee, LeaveRequest $request): array
    {
        return [
            'request' => $request->fresh()->toArray(),
            'steps' => LeaveRequestStep::query()->where('leave_request_id', $request->id)->orderBy('id')->get()->toArray(),
            'reservations' => LeaveBalanceReservationEvent::query()->where('employee_id', $employee->id)->orderBy('id')->get()->toArray(),
            'balances' => LeaveBalance::query()->where('employee_id', $employee->id)->orderBy('tahun')->get()->toArray(),
            'ledger' => LeaveBalanceLedger::query()->where('employee_id', $employee->id)->orderBy('id')->get()->toArray(),
            'facts_and_set' => $this->factAndSetSnapshot($employee),
            'audits' => AuditLog::query()->orderBy('id')->get()->toArray(),
            'notifications' => SimpegNotification::query()->where('user_id', $employee->id)->orderBy('id')->get()->toArray(),
            'queued_rollover_emails' => $this->queuedRolloverEmails(),
        ];
    }

    /** @return array<string, mixed> */
    private function approvalSnapshot(Employee $employee, LeaveRequest $request): array
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        return [
            'request' => $request->fresh()->toArray(),
            'steps' => LeaveRequestStep::query()->where('leave_request_id', $request->id)->orderBy('id')->get()->toArray(),
            'approvals' => LeaveApproval::query()->where('leave_request_id', $request->id)->orderBy('id')->get()->toArray(),
            'reservations' => LeaveBalanceReservationEvent::query()->where('employee_id', $employee->id)->orderBy('id')->get()->toArray(),
            'balances' => LeaveBalance::query()->where('employee_id', $employee->id)->orderBy('tahun')->get()->toArray(),
            'ledger' => LeaveBalanceLedger::query()->where('employee_id', $employee->id)->orderBy('id')->get()->toArray(),
            'facts_and_set' => $this->factAndSetSnapshot($employee),
            'proofs' => LeaveProof::query()->where('leave_request_id', $request->id)->orderBy('id')->get()->toArray(),
            'audits' => AuditLog::query()->orderBy('id')->get()->toArray(),
            'notifications' => SimpegNotification::query()->where('user_id', $employee->id)->orderBy('id')->get()->toArray(),
            'proof_files' => $disk->allFiles('leave-proofs'),
        ];
    }

    private function queuedRolloverEmails(): int
    {
        $queue = Queue::getFacadeRoot();

        if (! $queue instanceof QueueFake) {
            return DB::table('jobs')->count();
        }

        return $queue->pushed(SendSimpegNotificationEmailJob::class)
            ->filter(fn (SendSimpegNotificationEmailJob $job): bool => $job->eventKey === 'cuti.dikembalikan_karena_rollover')
            ->count();
    }
}
