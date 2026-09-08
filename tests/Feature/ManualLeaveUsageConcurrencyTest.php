<?php

namespace Tests\Feature;

use App\Actions\Cuti\StoreManualLeaveUsageAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageDocument;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('serial')]
class ManualLeaveUsageConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    // Bootstrap worker Laravel yang dingin pada Windows atau CI dapat melewati 30 detik.
    private const int WORKER_PROCESS_TIMEOUT_SECONDS = 120;

    private const int WORKER_READY_TIMEOUT_MILLISECONDS = 60_000;

    private ?string $raceDirectory = null;

    private string $usageStorageRoot;

    private string $originalLocalStorageRoot;

    protected function setUp(): void
    {
        $driver = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');

        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Race pemakaian manual wajib diuji pada PostgreSQL.');
        }

        parent::setUp();

        Carbon::setTestNow('2026-08-18 09:00:00');
        $this->seed(RbacSeeder::class);
        $this->originalLocalStorageRoot = (string) config('filesystems.disks.local.root');
        $this->usageStorageRoot = storage_path('framework/testing/manual-leave-storage-'.Str::uuid());
        File::ensureDirectoryExists($this->usageStorageRoot);
        config()->set('filesystems.disks.local.root', $this->usageStorageRoot);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if ($this->app !== null && DB::getDriverName() === 'pgsql') {
            $this->cleanupUsageFiles();
            $this->cleanupProtectedDatabaseEvidence();
        }

        Storage::forgetDisk('local');
        config()->set('filesystems.disks.local.root', $this->originalLocalStorageRoot);
        File::deleteDirectory($this->usageStorageRoot);

        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        parent::tearDown();
    }

    public function test_race_dua_manual_overlap_inklusif_hanya_membuat_satu_fact_aktif(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $type = $this->nonAnnualType();

        $outcomes = $this->runRace($employee, $type, [
            ['mode' => 'manual', 'actor_id' => $admin->id, 'start_date' => '2026-01-05', 'end_date' => '2026-01-07'],
            ['mode' => 'manual', 'actor_id' => $admin->id, 'start_date' => '2026-01-07', 'end_date' => '2026-01-09'],
        ]);

        $this->assertControlledSingleWinner($outcomes);
        $this->assertSame(1, LeaveUsageRecord::query()->where('record_status', 'active')->count());
        $this->assertDocumentFilesMatch(1);
    }

    public function test_race_manual_melawan_submit_normal_hanya_satu_mutasi_menang(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $type = $this->nonAnnualType();
        $submitActor = $this->employeeWithApprovalChain($employee);

        $outcomes = $this->runRace($employee, $type, [
            ['mode' => 'manual', 'actor_id' => $admin->id, 'start_date' => '2026-01-05', 'end_date' => '2026-01-07'],
            ['mode' => 'submit', 'actor_id' => $submitActor->id, 'start_date' => '2026-01-07', 'end_date' => '2026-01-09'],
        ]);

        $this->assertControlledSingleWinner($outcomes);
        $this->assertSame(
            1,
            LeaveUsageRecord::query()->where('record_status', 'active')->count()
                + LeaveRequest::query()->whereIn('status', [
                    'menunggu_approval',
                    'ditangguhkan',
                    'ditangguhkan_tugas_dinas',
                    'dikembalikan_karena_rollover',
                    'disetujui',
                ])->count(),
        );
        $winner = $outcomes->firstWhere('ok', true);
        $this->assertDocumentFilesMatch(($winner['mode'] ?? null) === 'manual' ? 1 : 0);
    }

    /** @param list<string> $modes */
    #[DataProvider('manualMutationOrders')]
    public function test_race_koreksi_dan_pembatalan_fakta_tahunan_memiliki_satu_pemenang_atomik(array $modes): void
    {
        $this->seed(ReferenceSeeder::class);
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
        ]);
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->sole();
        $original = app(StoreManualLeaveUsageAction::class)->execute(
            $employee->id,
            [
                'leave_type_id' => $annual->id,
                'tanggal_mulai' => '2026-01-05',
                'tanggal_selesai' => '2026-01-07',
                'alasan' => 'Fakta tahunan sebelum race.',
                'approval_steps' => $this->validManualApprovalPayload(),
            ],
            UploadedFile::fake()->create('awal.pdf', 20, 'application/pdf'),
            $admin,
        );
        $originalEvidence = Arr::only($original->getAttributes(), ['workdays', 'start_date', 'end_date', 'administrative_note', 'recorded_by']);
        $stepsBefore = $original->externalApprovalSteps()->orderBy('step_order')->get()->toArray();
        $this->assertSame(21, $employee->leaveBalances()->where('tahun', 2026)->sole()->sisa);

        $outcomes = $this->runRace($employee, $annual, array_map(fn (string $mode): array => [
            'mode' => $mode, 'actor_id' => $admin->id, 'record_id' => $original->id,
            'start_date' => '2026-01-05', 'end_date' => $mode === 'correct' ? '2026-01-08' : '2026-01-07',
        ], $modes), verifyEmployeeLock: true);

        $this->assertControlledSingleWinner($outcomes, 'usage_record');
        $this->assertSame($modes[0], $outcomes->firstWhere('ok', true)['mode']);
        $corrected = $outcomes->firstWhere('ok', true)['mode'] === 'correct';
        $original->refresh();
        $this->assertSame($originalEvidence, Arr::only($original->getAttributes(), array_keys($originalEvidence)));
        $this->assertSame($stepsBefore, $original->externalApprovalSteps()->orderBy('step_order')->get()->toArray());
        $this->assertSame($corrected ? LeaveUsageRecord::STATUS_SUPERSEDED : LeaveUsageRecord::STATUS_CANCELLED, $original->record_status);
        $this->assertSame($corrected ? 2 : 1, $employee->leaveUsageRecords()->count());
        $active = $employee->leaveUsageRecords()->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)->get();
        $this->assertCount($corrected ? 1 : 0, $active);
        if ($corrected) {
            $this->assertSame($original->id, $active->sole()->replaces_id);
            $this->assertSame(4, $active->sole()->workdays);
        }
        $balance = $employee->leaveBalances()->where('tahun', 2026)->sole();
        $this->assertSame($corrected ? 20 : 24, $balance->sisa);
        $this->assertSame($corrected ? 4 : 0, $balance->terpakai);
        $this->assertSame($corrected ? 2 : 6, $balance->sisa_n2);
        $this->assertSame(6, $balance->sisa_n1);
        $this->assertSame(12, $balance->sisa_tahun_berjalan);
        $this->assertSame(1, AuditLog::query()->whereIn('new_values->operation', [
            'manual_usage_corrected', 'manual_usage_cancelled',
        ])->count());
        foreach ([
            LeaveBalanceLedger::EVENT_USAGE_FACT_CANCELLED => $corrected ? 0 : 1,
            LeaveBalanceLedger::EVENT_USAGE_FACT_SUPERSEDED => $corrected ? 1 : 0,
            LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED => $corrected ? 2 : 1,
        ] as $event => $expected) {
            $this->assertSame($expected, LeaveBalanceLedger::query()->where('event_type', $event)->count());
        }
        $this->assertDocumentFilesMatch($corrected ? 2 : 1);
    }

    /** @return array<string, array{list<string>}> */
    public static function manualMutationOrders(): array
    {
        return ['koreksi lebih dahulu' => [['correct', 'cancel']], 'pembatalan lebih dahulu' => [['cancel', 'correct']]];
    }

    /**
     * @param  list<array{mode:string, actor_id:string, start_date:string, end_date:string, record_id?:string}>  $workers
     * @return Collection<int, array<string, mixed>>
     */
    private function runRace(Employee $employee, RefJenisCuti $type, array $workers, bool $verifyEmployeeLock = false): Collection
    {
        $directory = storage_path('framework/testing/manual-leave-race-'.Str::uuid());
        $this->raceDirectory = $directory;
        File::ensureDirectoryExists($directory);
        $barrier = $directory.'/go';
        $lockAcquired = $directory.'/employee-locked';
        $releaseLock = $directory.'/release-lock';
        $applicationPrefix = 'manual-race-'.Str::uuid();
        $processes = [];
        $results = [];

        try {
            foreach ($workers as $index => $worker) {
                $ready = "{$directory}/ready-{$index}";
                $result = "{$directory}/result-{$index}.json";
                $results[] = $result;
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Fixtures/ManualLeaveUsageRaceWorker.php'),
                    base64_encode(json_encode([
                        'mode' => $worker['mode'],
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'actor_id' => $worker['actor_id'],
                        'start_date' => $worker['start_date'],
                        'end_date' => $worker['end_date'],
                        'record_id' => $worker['record_id'] ?? '',
                        'now' => now()->toDateTimeString(),
                        'ready' => $ready,
                        'barrier' => $verifyEmployeeLock ? $barrier.'-'.$index : $barrier,
                        'application_name' => $applicationPrefix.'-'.$index,
                        'lock_acquired' => $verifyEmployeeLock && $index === 0 ? $lockAcquired : '',
                        'release_lock' => $releaseLock,
                        'result' => $result,
                        'storage_root' => $this->usageStorageRoot,
                    ], JSON_THROW_ON_ERROR)),
                ], base_path(), timeout: self::WORKER_PROCESS_TIMEOUT_SECONDS);
                $process->start();
                $processes[] = $process;
            }

            foreach (array_keys($workers) as $index) {
                $this->assertTrue($this->waitFor(
                    "{$directory}/ready-{$index}",
                    self::WORKER_READY_TIMEOUT_MILLISECONDS,
                ));
            }

            if ($verifyEmployeeLock) {
                File::put($barrier.'-0', 'go');
                $this->assertTrue($this->waitFor($lockAcquired, self::WORKER_READY_TIMEOUT_MILLISECONDS));
                File::put($barrier.'-1', 'go');
                $this->assertTrue($this->waitForEmployeeLockContention($applicationPrefix), 'Worker kedua wajib menunggu mutex pegawai yang dipegang worker pertama.');
                File::put($releaseLock, 'release');
            } else {
                File::put($barrier, 'go');
            }

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }

            return collect($results)->map($this->readRaceResult(...));
        } finally {
            File::put($releaseLock, 'release');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

        }
    }

    /** Barrier bootstrap saja tidak membuktikan transaksi overlap; pg_blocking_pids memeriksa penantian lock nyata. */
    private function waitForEmployeeLockContention(string $applicationPrefix): bool
    {
        $deadline = microtime(true) + (self::WORKER_READY_TIMEOUT_MILLISECONDS / 1000);
        do {
            $waiting = DB::selectOne(<<<'SQL'
SELECT EXISTS (
    SELECT 1 FROM pg_stat_activity waiter
    JOIN pg_stat_activity blocker ON blocker.pid = ANY(pg_blocking_pids(waiter.pid))
    WHERE waiter.application_name = ? AND blocker.application_name = ?
) AS waiting
SQL, [$applicationPrefix.'-1', $applicationPrefix.'-0']);
            if ($waiting->waiting) {
                return true;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function assertControlledSingleWinner(Collection $outcomes, string $errorField = 'tanggal_mulai'): void
    {
        $diagnostic = $outcomes->toJson();
        $this->assertSame(1, $outcomes->where('ok', true)->count(), $diagnostic);
        $this->assertSame(1, $outcomes->where('ok', false)->count(), $diagnostic);
        $loser = $outcomes->firstWhere('ok', false);
        $this->assertSame(ValidationException::class, $loser['class'] ?? null, $diagnostic);
        $this->assertArrayHasKey($errorField, $loser['errors'] ?? [], $diagnostic);
    }

    /** @return array<string, mixed> */
    private function readRaceResult(string $path): array
    {
        $decoded = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new \UnexpectedValueException('Hasil worker race pemakaian manual wajib berupa objek JSON.');
        }

        $result = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new \UnexpectedValueException('Kunci hasil worker race pemakaian manual wajib berupa string.');
            }

            $result[$key] = $value;
        }

        return $result;
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

    private function employeeWithApprovalChain(Employee $employee): User
    {
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        SupervisorAssignment::query()->create([
            'employee_id' => $employee->id,
            'supervisor_id' => $kepalaBagian->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);
        $chain = LeaveApprovalChain::query()->create([
            'employee_id' => $employee->id,
            'name' => 'Chain uji race overlap manual',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Fixture uji race overlap manual.',
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

        return User::factory()->pegawai()->create(['employee_id' => $employee->id]);
    }

    private function nonAnnualType(): RefJenisCuti
    {
        return RefJenisCuti::query()->create([
            'nama' => 'Cuti Sakit Race',
            'code' => 'sakit-race',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
    }

    private function cleanupUsageFiles(): void
    {
        if (! Schema::hasTable('leave_usage_documents')) {
            return;
        }

        foreach (LeaveUsageDocument::query()->pluck('path') as $path) {
            Storage::disk(LeaveUsageDocument::STORAGE_DISK)->delete((string) $path);
        }
    }

    private function assertDocumentFilesMatch(int $expectedCount): void
    {
        $metadataPaths = LeaveUsageDocument::query()->orderBy('path')->pluck('path')->all();
        $storedPaths = Storage::disk(LeaveUsageDocument::STORAGE_DISK)
            ->allFiles(LeaveUsageDocument::PATH_PREFIX);
        sort($storedPaths);

        $this->assertCount($expectedCount, $metadataPaths);
        $this->assertCount($expectedCount, $storedPaths);
        $this->assertSame($metadataPaths, $storedPaths, 'Race loser tidak boleh meninggalkan file orphan.');
    }

    private function cleanupProtectedDatabaseEvidence(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS leave_balance_ledger_no_update_delete ON leave_balance_ledger;
DROP TRIGGER IF EXISTS leave_balance_ledger_no_truncate ON leave_balance_ledger;
DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs;
DROP TRIGGER IF EXISTS audit_logs_append_only_truncate ON audit_logs;
DROP TRIGGER IF EXISTS leave_usage_document_restrict_update ON leave_usage_documents;
DROP TRIGGER IF EXISTS leave_usage_document_no_delete ON leave_usage_documents;
DROP TRIGGER IF EXISTS leave_usage_document_no_truncate ON leave_usage_documents;
DROP TRIGGER IF EXISTS leave_usage_record_no_delete ON leave_usage_records;
DROP TRIGGER IF EXISTS leave_usage_record_no_truncate ON leave_usage_records;
DROP TRIGGER IF EXISTS leave_usage_external_approval_no_mutation ON leave_usage_external_approval_steps;
DROP TRIGGER IF EXISTS leave_usage_external_approval_no_truncate ON leave_usage_external_approval_steps;
SQL);

        if (Schema::hasTable('leave_usage_documents')) {
            DB::table('leave_usage_documents')->delete();
        }

        DB::table('leave_usage_external_approval_steps')->delete();
        DB::table('leave_usage_records')->delete();
        DB::table('leave_balance_ledger')->delete();
        DB::table('leave_balance_reservation_events')->delete();
        DB::table('audit_logs')->delete();
        DB::table('notifications')->delete();
        DB::table('leave_requests')->delete();
        DB::table('leave_request_cases')->delete();
    }
}
