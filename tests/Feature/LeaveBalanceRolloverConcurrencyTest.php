<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\SimpegNotification;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Cuti\LeaveUsageReconciliationService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('serial')]
class LeaveBalanceRolloverConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const MARKER_LOCK = 82620262;

    private const NOTIFICATION_LOCK = 82620261;

    private const APPROVER_SNAPSHOT_LOCK = 82620263;

    private ?string $raceDirectory = null;

    protected function setUp(): void
    {
        $driver = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');

        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Race rollover wajib dijalankan pada PostgreSQL.');
        }

        parent::setUp();
        Carbon::setTestNow('2026-08-18 10:00:00');
        $this->seed(ReferenceSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if ($this->app !== null && DB::getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_unlock_all()');
            $this->dropPauseTriggers();
            $this->cleanupProtectedDatabaseEvidence();
        }

        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        parent::tearDown();
    }

    public function test_submit_yang_commit_setelah_scan_awal_tetap_dikembalikan_oleh_rescan_rollover(): void
    {
        $fixture = $this->createFixture();
        $processes = [];

        try {
            $this->installNotificationPauseTrigger();
            DB::statement('SELECT pg_advisory_lock(?)', [self::NOTIFICATION_LOCK]);

            $submit = $this->startWorker('submit-a', 'submit', $fixture);
            $processes[] = $submit['process'];
            $this->assertTrue($this->waitForDatabaseLock('simpeg_rollover_submit_a', 30_000));
            $this->assertSame(0, LeaveRequest::query()->where('employee_id', $fixture['employee']->id)->count());

            $rollover = $this->startWorker('rollover-a', 'rollover', $fixture);
            $processes[] = $rollover['process'];
            $this->assertTrue($this->waitForDatabaseLock('simpeg_rollover_rollover_a', 30_000));

            DB::statement('SELECT pg_advisory_unlock(?)', [self::NOTIFICATION_LOCK]);
            $outcomes = collect([
                $this->finishWorker($submit),
                $this->finishWorker($rollover),
            ]);

            $diagnostic = $outcomes->toJson();
            $this->assertSame(2, $outcomes->where('ok', true)->count(), $diagnostic);
            $request = LeaveRequest::query()
                ->where('employee_id', $fixture['employee']->id)
                ->where('jenis_cuti_id', $fixture['annual']->id)
                ->sole();
            $this->assertSame(LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER, $request->status);
            $this->assertSame(2026, $request->rollover_source_year);
            $this->assertSame(2027, $request->rollover_target_year);
            $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
                ->where('leave_request_id', $request->id)
                ->sum('amount'));
            $this->assertSame(1, LeaveBalanceReservationEvent::query()
                ->where('leave_request_id', $request->id)
                ->where('event_type', LeaveBalanceReservationEvent::EVENT_RELEASED)
                ->count());
            $this->assertSame(1, SimpegNotification::query()
                ->where('user_id', $fixture['employee']->id)
                ->where('type', 'cuti.dikembalikan_karena_rollover')
                ->count());
            $this->assertSame(1, LeaveBalanceLedger::query()
                ->where('dedup_key', "{$fixture['employee']->id}:2027:rollover_applied")
                ->count());
        } finally {
            DB::statement('SELECT pg_advisory_unlock_all()');
            $this->stopWorkers($processes);
            $this->dropPauseTriggers();
        }
    }

    public function test_submit_yang_menunggu_marker_rollover_gagal_terkontrol_tanpa_efek_parsial(): void
    {
        $fixture = $this->createFixture();
        $processes = [];

        try {
            $this->installMarkerPauseTrigger();
            DB::statement('SELECT pg_advisory_lock(?)', [self::MARKER_LOCK]);

            $rollover = $this->startWorker('rollover-b', 'rollover', $fixture);
            $processes[] = $rollover['process'];
            $this->assertTrue($this->waitForDatabaseLock('simpeg_rollover_rollover_b', 30_000));

            $submit = $this->startWorker('submit-b', 'submit', $fixture);
            $processes[] = $submit['process'];
            $this->assertTrue($this->waitForDatabaseLock('simpeg_rollover_submit_b', 30_000));

            DB::statement('SELECT pg_advisory_unlock(?)', [self::MARKER_LOCK]);
            $outcomes = collect([
                $this->finishWorker($rollover),
                $this->finishWorker($submit),
            ]);

            $diagnostic = $outcomes->toJson();
            $this->assertSame(1, $outcomes->where('ok', true)->count(), $diagnostic);
            $loser = $outcomes->firstWhere('ok', false);
            $this->assertSame(ValidationException::class, $loser['class'] ?? null, $diagnostic);
            $this->assertStringContainsString(
                'sudah ditutup oleh rollover',
                (string) ($loser['errors']['status'][0] ?? ''),
                $diagnostic,
            );
            $this->assertSame(0, LeaveRequest::query()
                ->where('employee_id', $fixture['employee']->id)
                ->where('jenis_cuti_id', $fixture['annual']->id)
                ->count());
            $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
                ->where('employee_id', $fixture['employee']->id)
                ->where('tahun', 2026)
                ->sum('amount'));
            $this->assertSame(1, LeaveBalanceLedger::query()
                ->where('dedup_key', "{$fixture['employee']->id}:2027:rollover_applied")
                ->count());
        } finally {
            DB::statement('SELECT pg_advisory_unlock_all()');
            $this->stopWorkers($processes);
            $this->dropPauseTriggers();
        }
    }

    public function test_submit_mempertahankan_lock_approver_sampai_snapshot_terbentuk(): void
    {
        $fixture = $this->createFixture();
        $statusNonaktif = RefStatusPegawai::query()->create([
            'kode' => 'NONAKTIF_RACE_SUBMIT',
            'nama' => 'Nonaktif Race Submit',
            'kelompok' => 'Nonaktif',
            'keterangan' => 'Fixture serialisasi lifecycle approver saat submit.',
            'is_active' => true,
            'is_default' => false,
        ]);
        $processes = [];

        try {
            $this->installApproverSnapshotPauseTrigger();
            DB::statement('SELECT pg_advisory_lock(?)', [self::APPROVER_SNAPSHOT_LOCK]);

            $submit = $this->startWorker('submit-approver-lock', 'submit', $fixture);
            $processes[] = $submit['process'];
            $this->assertTrue($this->waitForDatabaseLock('simpeg_rollover_submit_approver_lock', 30_000));

            $status = $this->startWorker('status-approver-lock', 'status', $fixture, [
                'target_employee_id' => $fixture['kepala_bagian']->id,
                'status_id' => $statusNonaktif->id,
            ]);
            $processes[] = $status['process'];

            $this->assertTrue(
                $this->waitForDatabaseLock('simpeg_rollover_status_approver_lock', 5_000),
                'Writer lifecycle wajib menunggu lock approver sampai snapshot pengajuan selesai dibentuk.',
            );
            $this->assertFalse(File::exists($status['result']));

            DB::statement('SELECT pg_advisory_unlock(?)', [self::APPROVER_SNAPSHOT_LOCK]);
            $submitResult = $this->finishWorker($submit);
            $statusResult = $this->finishWorker($status);

            $this->assertTrue($submitResult['ok'] ?? false, json_encode($submitResult, JSON_THROW_ON_ERROR));
            $this->assertTrue($statusResult['ok'] ?? false, json_encode($statusResult, JSON_THROW_ON_ERROR));
            $request = LeaveRequest::query()
                ->where('employee_id', $fixture['employee']->id)
                ->sole();
            $this->assertDatabaseHas('leave_request_steps', [
                'leave_request_id' => $request->id,
                'step_type' => 'kepala_bagian',
                'approver_employee_id' => $fixture['kepala_bagian']->id,
            ]);
            $this->assertSame($statusNonaktif->id, $fixture['kepala_bagian']->refresh()->status_pegawai_id);
        } finally {
            DB::statement('SELECT pg_advisory_unlock_all()');
            $this->stopWorkers($processes);
            $this->dropPauseTriggers();
        }
    }

    /** @return array{employee:Employee, actor:User, annual:RefJenisCuti, kepala_bagian:Employee} */
    private function createFixture(): array
    {
        $pns = RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail();
        $employee = Employee::factory()->create([
            'email' => 'rollover-race-'.Str::uuid().'@example.test',
            'jenis_pegawai_id' => $pns->id,
        ]);
        $actor = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-ROLLOVER-RACE',
            'tanggal_sk' => '2020-01-01',
        ]);
        SupervisorAssignment::query()->create([
            'employee_id' => $employee->id,
            'supervisor_id' => $kepalaBagian->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);
        $chain = LeaveApprovalChain::query()->create([
            'employee_id' => $employee->id,
            'name' => 'Chain race rollover',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Fixture race rollover.',
        ]);
        $chain->steps()->createMany([
            [
                'step_order' => 1,
                'step_type' => 'kepala_bagian',
                'role_label' => 'Kepala Bagian',
                'approver_employee_id' => $kepalaBagian->id,
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
        $admin = User::factory()->adminKepegawaian()->create();
        app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 0],
            Carbon::parse('2026-08-18'),
            'Rekonsiliasi fixture race rollover.',
            $admin,
        );

        return [
            'employee' => $employee,
            'actor' => $actor,
            'annual' => RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail(),
            'kepala_bagian' => $kepalaBagian,
        ];
    }

    /**
     * @param  array{employee:Employee, actor:User, annual:RefJenisCuti, kepala_bagian:Employee}  $fixture
     * @param  array<string, string>  $extra
     * @return array{process:Process,result:string}
     */
    private function startWorker(string $label, string $operation, array $fixture, array $extra = []): array
    {
        $directory = $this->raceDirectory ?? storage_path('framework/testing/leave-rollover-race-'.Str::uuid());
        $this->raceDirectory = $directory;
        File::ensureDirectoryExists($directory);
        $ready = "{$directory}/{$label}.ready";
        $barrier = "{$directory}/{$label}.go";
        $result = "{$directory}/{$label}.json";
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/LeaveBalanceRolloverRaceWorker.php'),
            base64_encode(json_encode(array_merge([
                'operation' => $operation,
                'application_name' => 'simpeg_rollover_'.str_replace('-', '_', $label),
                'employee_id' => $fixture['employee']->id,
                'actor_user_id' => $fixture['actor']->id,
                'leave_type_id' => $fixture['annual']->id,
                'source_year' => '2026',
                'now' => '2027-01-01 00:05:00',
                'ready' => $ready,
                'barrier' => $barrier,
                'result' => $result,
            ], $extra), JSON_THROW_ON_ERROR)),
        ], base_path(), timeout: 60);
        $process->start();
        $this->assertTrue($this->waitForFile($ready, 30_000));
        File::put($barrier, 'go');

        return compact('process', 'result');
    }

    /** @param array{process:Process,result:string} $worker */
    private function finishWorker(array $worker): array
    {
        $worker['process']->wait();
        $this->assertTrue($worker['process']->isSuccessful(), $worker['process']->getErrorOutput());
        $this->assertTrue($this->waitForFile($worker['result'], 5_000));

        return json_decode(File::get($worker['result']), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param list<Process> $processes */
    private function stopWorkers(array $processes): void
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    private function waitForFile(string $path, int $timeoutMilliseconds): bool
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            clearstatcache(true, $path);

            if (File::exists($path)) {
                return true;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return File::exists($path);
    }

    private function waitForDatabaseLock(string $applicationName, int $timeoutMilliseconds): bool
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            if (DB::table('pg_stat_activity')
                ->where('application_name', $applicationName)
                ->where('wait_event_type', 'Lock')
                ->exists()) {
                return true;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function installApproverSnapshotPauseTrigger(): void
    {
        DB::unprepared(sprintf(<<<'SQL'
CREATE OR REPLACE FUNCTION test_pause_submit_approver_snapshot() RETURNS trigger AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(%d);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER test_pause_submit_approver_snapshot
BEFORE INSERT ON leave_request_steps
FOR EACH ROW EXECUTE FUNCTION test_pause_submit_approver_snapshot();
SQL, self::APPROVER_SNAPSHOT_LOCK));
    }

    private function installNotificationPauseTrigger(): void
    {
        DB::unprepared(sprintf(<<<'SQL'
CREATE OR REPLACE FUNCTION test_pause_rollover_submit_notification() RETURNS trigger AS $$
BEGIN
    IF NEW.type = 'cuti.pengajuan_baru' THEN
        PERFORM pg_advisory_xact_lock(%d);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER test_pause_rollover_submit_notification
AFTER INSERT ON notifications
FOR EACH ROW EXECUTE FUNCTION test_pause_rollover_submit_notification();
SQL, self::NOTIFICATION_LOCK));
    }

    private function installMarkerPauseTrigger(): void
    {
        DB::unprepared(sprintf(<<<'SQL'
CREATE OR REPLACE FUNCTION test_pause_rollover_marker() RETURNS trigger AS $$
BEGIN
    IF NEW.event_type = 'rollover_applied' THEN
        PERFORM pg_advisory_xact_lock(%d);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER test_pause_rollover_marker
AFTER INSERT ON leave_balance_ledger
FOR EACH ROW EXECUTE FUNCTION test_pause_rollover_marker();
SQL, self::MARKER_LOCK));
    }

    private function dropPauseTriggers(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS test_pause_rollover_submit_notification ON notifications;
DROP FUNCTION IF EXISTS test_pause_rollover_submit_notification();
DROP TRIGGER IF EXISTS test_pause_rollover_marker ON leave_balance_ledger;
DROP FUNCTION IF EXISTS test_pause_rollover_marker();
DROP TRIGGER IF EXISTS test_pause_submit_approver_snapshot ON leave_request_steps;
DROP FUNCTION IF EXISTS test_pause_submit_approver_snapshot();
SQL);
    }

    private function cleanupProtectedDatabaseEvidence(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS leave_balance_ledger_no_update_delete ON leave_balance_ledger;
DROP TRIGGER IF EXISTS leave_balance_ledger_no_truncate ON leave_balance_ledger;
DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs;
DROP TRIGGER IF EXISTS audit_logs_append_only_truncate ON audit_logs;
DROP TRIGGER IF EXISTS leave_usage_membership_validate ON leave_usage_reconciliation_memberships;
DROP TRIGGER IF EXISTS leave_usage_membership_no_update_delete ON leave_usage_reconciliation_memberships;
DROP TRIGGER IF EXISTS leave_usage_membership_no_truncate ON leave_usage_reconciliation_memberships;
DROP TRIGGER IF EXISTS leave_usage_record_no_delete ON leave_usage_records;
DROP TRIGGER IF EXISTS leave_usage_record_no_truncate ON leave_usage_records;
DROP TRIGGER IF EXISTS leave_usage_reconciliation_no_delete ON leave_usage_reconciliation_sets;
DROP TRIGGER IF EXISTS leave_usage_reconciliation_no_truncate ON leave_usage_reconciliation_sets;
SQL);

        foreach ([
            'leave_usage_documents',
            'leave_usage_reconciliation_memberships',
            'leave_usage_records',
            'leave_usage_reconciliation_sets',
            'leave_balance_ledger',
            'leave_balance_reservation_events',
            'audit_logs',
            'notifications',
            'jobs',
            'leave_proofs',
            'leave_approvals',
            'leave_request_steps',
            'leave_requests',
            'leave_request_cases',
            'leave_balances',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }
}
