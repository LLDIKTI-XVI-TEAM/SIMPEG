<?php

namespace Tests\Feature;

use App\Actions\Cuti\ReconcileAnnualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceReservationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('serial')]
class LeaveApprovalUsageConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $raceDirectory = null;

    private string $proofStorageRoot;

    private string $originalLocalStorageRoot;

    protected function setUp(): void
    {
        $driver = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');

        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Race persetujuan final wajib diuji pada PostgreSQL.');
        }

        parent::setUp();

        Carbon::setTestNow('2026-08-18 10:00:00');
        $this->seed(RbacSeeder::class);
        $this->originalLocalStorageRoot = (string) config('filesystems.disks.local.root');
        $this->proofStorageRoot = storage_path('framework/testing/leave-approval-storage-'.Str::uuid());
        File::ensureDirectoryExists($this->proofStorageRoot);
        config()->set('filesystems.disks.local.root', $this->proofStorageRoot);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if ($this->app !== null && DB::getDriverName() === 'pgsql') {
            $this->cleanupProtectedDatabaseEvidence();
        }

        Storage::forgetDisk('local');
        config()->set('filesystems.disks.local.root', $this->originalLocalStorageRoot);
        File::deleteDirectory($this->proofStorageRoot);

        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        parent::tearDown();
    }

    public function test_dua_persetujuan_final_paralel_hanya_menghasilkan_satu_efek_terminal_tanpa_deadlock(): void
    {
        $fixture = $this->makeCommittedFinalApprovalFixture();

        $outcomes = $this->runRace($fixture);

        $diagnostic = $outcomes->toJson();
        $this->assertSame(1, $outcomes->where('ok', true)->count(), $diagnostic);
        $this->assertSame(1, $outcomes->where('ok', false)->count(), $diagnostic);
        $winner = $outcomes->firstWhere('ok', true);
        $loser = $outcomes->firstWhere('ok', false);
        $this->assertSame('disetujui', $winner['status'] ?? null, $diagnostic);
        $this->assertSame(ValidationException::class, $loser['class'] ?? null, $diagnostic);
        $this->assertSame(
            ['Pengajuan cuti ini belum dapat diproses oleh approver.'],
            $loser['errors']['status'] ?? null,
            $diagnostic,
        );

        $this->assertSame('disetujui', $fixture['request']->fresh()->status);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $fixture['request']->id,
            'status' => 'approved',
            'is_final' => true,
        ]);
        $this->assertSame(1, LeaveApproval::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->where('action', 'APPROVE')
            ->count());

        $fact = LeaveUsageRecord::query()->where('leave_request_id', $fixture['request']->id)->sole();
        $this->assertSame($winner['fact_id'], $fact->id);
        $this->assertSame(LeaveUsageRecord::SOURCE_APPROVED_REQUEST, $fact->source_type);
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $fact->record_status);
        $this->assertSame(1, LeaveBalanceLedger::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED)
            ->count());
        $this->assertSame($fixture['recalculation_before'] + 1, LeaveBalanceLedger::query()
            ->where('employee_id', $fixture['pemohon']->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count());
        $this->assertSame(0, LeaveBalanceLedger::query()
            ->where('employee_id', $fixture['pemohon']->id)
            ->whereIn('event_type', ['leave_deducted', 'manual_adjustment', 'opening_balance_set'])
            ->count());

        $this->assertSame(0, (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->sum('amount'));
        $conversion = LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->where('event_type', LeaveBalanceReservationEvent::EVENT_CONVERTED)
            ->sole();
        $this->assertSame(-3, $conversion->amount);
        $this->assertSame(1, AuditLog::query()
            ->where('event', 'LEAVE_BALANCE_RESERVATION_CONVERTED')
            ->where('auditable_id', $conversion->id)
            ->count());

        $proof = LeaveProof::query()->where('leave_request_id', $fixture['request']->id)->sole();
        $this->assertSame($winner['proof_id'], $proof->id);
        $this->assertSame($winner['proof_path'], $proof->document_path);
        $this->assertMatchesRegularExpression(
            '#^leave-proofs/'.$fixture['request']->id.'/[0-9a-f-]{36}\.pdf$#',
            (string) $proof->document_path,
        );
        $metadataPaths = [(string) $proof->document_path];
        $storedPaths = Storage::disk('local')->allFiles('leave-proofs');
        sort($storedPaths);
        $this->assertSame($metadataPaths, $storedPaths, 'Race loser tidak boleh meninggalkan PDF orphan.');
        $this->assertTrue(Storage::disk('local')->exists((string) $proof->document_path));

        $this->assertSame(1, AuditLog::query()
            ->where('event', 'CREATE')
            ->where('auditable_type', 'LeaveUsageRecord')
            ->where('auditable_id', $fact->id)
            ->count());
        $this->assertSame(1, AuditLog::query()
            ->where('event', 'LEAVE_PROOF_GENERATED')
            ->where('auditable_id', $proof->id)
            ->count());
        $this->assertSame(1, AuditLog::query()
            ->where('event', 'DECIDE')
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $fixture['request']->id)
            ->count());
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $fixture['pemohon']->id)
            ->where('type', 'cuti.disetujui')
            ->count());
    }

    public function test_persetujuan_final_dan_penangguhan_tugas_dinas_paralel_tidak_deadlock_atau_mencampur_efek_terminal(): void
    {
        $fixture = $this->makeCommittedFinalApprovalFixture();

        $outcomes = $this->runRace($fixture, ['approve', 'duty_postponement']);

        $diagnostic = $outcomes->toJson();
        $this->assertSame(1, $outcomes->where('ok', true)->count(), $diagnostic);
        $this->assertSame(1, $outcomes->where('ok', false)->count(), $diagnostic);
        $winner = $outcomes->firstWhere('ok', true);
        $loser = $outcomes->firstWhere('ok', false);
        $this->assertSame(ValidationException::class, $loser['class'] ?? null, $diagnostic);
        $this->assertArrayHasKey('status', $loser['errors'] ?? [], $diagnostic);
        $this->assertContains($winner['status'] ?? null, [
            'disetujui',
            LeaveRequest::STATUS_DUTY_POSTPONED,
        ]);
        $this->assertSame(1, LeaveApproval::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->count());

        $approvalWon = ($winner['operation'] ?? null) === 'approve';
        $this->assertSame($approvalWon ? 'disetujui' : LeaveRequest::STATUS_DUTY_POSTPONED, $fixture['request']->fresh()->status);
        $this->assertSame($approvalWon ? 1 : 0, LeaveUsageRecord::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->count());
        $this->assertSame($approvalWon ? 1 : 0, LeaveProof::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->count());
        $this->assertSame($approvalWon ? 0 : 1, LeaveBalanceLedger::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)
            ->count());
        $this->assertSame($approvalWon ? 1 : 0, LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->where('event_type', LeaveBalanceReservationEvent::EVENT_CONVERTED)
            ->count());
        $this->assertSame($approvalWon ? 0 : 1, LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->where('event_type', LeaveBalanceReservationEvent::EVENT_RELEASED)
            ->count());

        $metadataPaths = LeaveProof::query()
            ->where('leave_request_id', $fixture['request']->id)
            ->pluck('document_path')
            ->filter()
            ->sort()
            ->values()
            ->all();
        $storedPaths = Storage::disk('local')->allFiles('leave-proofs');
        sort($storedPaths);
        $this->assertSame($metadataPaths, $storedPaths, 'Race terminal tidak boleh meninggalkan PDF orphan.');
    }

    /**
     * @return array{
     *     pemohon: Employee,
     *     request: LeaveRequest,
     *     approver: Employee,
     *     approver_user: User,
     *     recalculation_before: int
     * }
     */
    private function makeCommittedFinalApprovalFixture(): array
    {
        $annual = RefJenisCuti::query()->create([
            'nama' => 'Cuti Tahunan Race Approval',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $pemohon = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Race Approval',
            'email' => 'pemohon-race-'.Str::uuid().'@example.test',
        ]);
        Appointment::create([
            'employee_id' => $pemohon->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);
        $pemohonUser = User::factory()->pegawai()->create(['employee_id' => $pemohon->id]);
        $approver = Employee::factory()->create(['nama_lengkap' => 'PYBMC Race Approval']);
        $approverUser = User::factory()->create([
            'name' => 'User PYBMC Race',
            'role' => 'pimpinan',
            'employee_id' => $approver->id,
        ]);
        $admin = User::factory()->adminKepegawaian()->create();
        app(ReconcileAnnualLeaveUsageAction::class)->execute(
            $pemohon->id,
            [
                'balance_year' => 2026,
                'usage_n2' => 0,
                'usage_n1' => 0,
                'usage_current' => 0,
                'administrative_note' => 'Rekonsiliasi fixture race persetujuan.',
            ],
            $admin,
            $this->requestFor($admin),
        );
        $request = LeaveRequest::query()->create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $annual->id,
            'tanggal_mulai' => '2026-08-20',
            'tanggal_selesai' => '2026-08-24',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Pengajuan race approval.',
            'alamat_selama_cuti' => 'Alamat privat race.',
            'nomor_telepon' => '081234000000',
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
        app(LeaveBalanceReservationService::class)->reserveForNewRequest(
            $request->fresh('jenisCuti'),
            $pemohonUser,
        );
        $recalculationBefore = LeaveBalanceLedger::query()
            ->where('employee_id', $pemohon->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
            ->count();

        return [
            'pemohon' => $pemohon,
            'request' => $request->fresh(),
            'approver' => $approver,
            'approver_user' => $approverUser,
            'recalculation_before' => $recalculationBefore,
        ];
    }

    /**
     * @param  array{request: LeaveRequest, approver: Employee, approver_user: User}  $fixture
     * @return Collection<int, array<string, mixed>>
     */
    private function runRace(array $fixture, array $operations = ['approve', 'approve']): Collection
    {
        $directory = storage_path('framework/testing/leave-approval-race-'.Str::uuid());
        $this->raceDirectory = $directory;
        File::ensureDirectoryExists($directory);
        $barrier = $directory.'/go';
        $processes = [];
        $results = [];

        try {
            foreach ($operations as $index => $operation) {
                $ready = "{$directory}/ready-{$index}";
                $result = "{$directory}/result-{$index}.json";
                $results[] = $result;
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Fixtures/LeaveApprovalRaceWorker.php'),
                    base64_encode(json_encode([
                        'leave_request_id' => $fixture['request']->id,
                        'approver_employee_id' => $fixture['approver']->id,
                        'actor_user_id' => $fixture['approver_user']->id,
                        'operation' => $operation,
                        'ready' => $ready,
                        'barrier' => $barrier,
                        'result' => $result,
                        'storage_root' => $this->proofStorageRoot,
                    ], JSON_THROW_ON_ERROR)),
                ], base_path(), timeout: 60);
                $process->start();
                $processes[] = $process;
            }

            foreach (array_keys($operations) as $index) {
                $this->assertTrue($this->waitFor("{$directory}/ready-{$index}", 30_000));
            }

            File::put($barrier, 'go');

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }

            return collect($results)->map($this->readRaceResult(...));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    private function waitFor(string $path, int $timeoutMilliseconds): bool
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

    /** @return array<string, mixed> */
    private function readRaceResult(string $path): array
    {
        $decoded = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new \UnexpectedValueException('Hasil worker race approval wajib berupa objek JSON.');
        }

        $result = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new \UnexpectedValueException('Kunci hasil worker race approval wajib berupa string.');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function requestFor(User $actor): Request
    {
        $request = Request::create('/cuti/approval', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
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
