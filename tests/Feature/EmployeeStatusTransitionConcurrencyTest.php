<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusTransition;
use App\Models\RefStatusPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Employees\EmployeeStatusActorContext;
use App\Services\Employees\EmployeeStatusTransitionService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('serial')]
class EmployeeStatusTransitionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const ADVISORY_LOCK_KEY = 19082701;

    private ?string $raceDirectory = null;

    private ?string $documentStorageRoot = null;

    private ?string $originalDocumentStorageRoot = null;

    protected function setUp(): void
    {
        $driver = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Race transisi status wajib diuji pada PostgreSQL.');
        }

        parent::setUp();
        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);

        $this->originalDocumentStorageRoot = (string) config('filesystems.disks.employee_documents.root');
        $this->documentStorageRoot = storage_path('framework/testing/status-transition-storage-'.Str::uuid());
        File::ensureDirectoryExists($this->documentStorageRoot);
        config()->set('filesystems.disks.employee_documents.root', $this->documentStorageRoot);
        Storage::forgetDisk(Document::STORAGE_DISK);
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_unlock_all()');
            DB::unprepared('DROP TRIGGER IF EXISTS zz_status_document_test_barrier ON documents');
            DB::unprepared('DROP FUNCTION IF EXISTS status_document_test_barrier()');
        }

        Storage::forgetDisk(Document::STORAGE_DISK);
        if ($this->originalDocumentStorageRoot !== null) {
            config()->set('filesystems.disks.employee_documents.root', $this->originalDocumentStorageRoot);
        }
        if ($this->documentStorageRoot !== null) {
            File::deleteDirectory($this->documentStorageRoot);
        }
        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        parent::tearDown();
    }

    /** Dua writer harus mengunci employee sebelum membuat metadata dokumen yang mengambil FK key-share. */
    public function test_dua_attachment_status_paralel_memakai_urutan_lock_yang_konsisten_tanpa_deadlock(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $status = RefStatusPegawai::query()->where('kode', 'MUTASI')->firstOrFail();
        $tanggalEfektif = now('Asia/Makassar')->addDay()->toDateString();
        $race = $this->racePaths();
        $processes = [];
        $pids = [];
        $advisoryLocked = false;

        $this->installDocumentBarrier();
        DB::statement('SELECT pg_advisory_lock(?)', [self::ADVISORY_LOCK_KEY]);
        $advisoryLocked = true;

        try {
            foreach ([0, 1] as $worker) {
                $process = $this->worker([
                    'actor_id' => $actor->id,
                    'employee_id' => $employee->id,
                    'status_id' => $status->id,
                    'tanggal_efektif' => $tanggalEfektif,
                    'worker' => (string) $worker,
                    'ready' => $race["ready_{$worker}"],
                    'barrier' => $race['barrier'],
                    'result' => $race["result_{$worker}"],
                    'storage_root' => (string) $this->documentStorageRoot,
                ]);
                $process->start();
                $processes[] = $process;
            }

            foreach ([0, 1] as $worker) {
                $this->assertTrue($this->waitFor($race["ready_{$worker}"], 30_000));
                $pids[] = (int) File::get($race["ready_{$worker}"]);
            }

            File::put($race['barrier'], 'go');
            $this->assertTrue(
                $this->waitForBothWorkersToBlock($pids, 30_000),
                'Kedua worker harus mencapai salah satu lock sebelum barrier dilepas.',
            );

            // Dengan lock employee-first, hanya winner yang mencapai INSERT documents;
            // worker kedua menunggu employee dan belum mengambil FK key-share.
            $this->assertSame(1, $this->waitingAdvisoryLockCount($pids));

            DB::statement('SELECT pg_advisory_unlock(?)', [self::ADVISORY_LOCK_KEY]);
            $advisoryLocked = false;

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }

            $outcomes = collect([0, 1])->map(
                fn (int $worker): array => json_decode(
                    File::get($race["result_{$worker}"]),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
            );
            $diagnostic = $outcomes->toJson();
            $this->assertSame(1, $outcomes->where('ok', true)->count(), $diagnostic);
            $this->assertSame(1, $outcomes->where('ok', false)->count(), $diagnostic);
            $this->assertSame(ValidationException::class, $outcomes->firstWhere('ok', false)['class'] ?? null, $diagnostic);
            $this->assertDatabaseCount('employee_status_transitions', 1);
            $this->assertDatabaseCount('documents', 1);
            $this->assertCount(1, Storage::disk(Document::STORAGE_DISK)->allFiles());
            $this->assertFalse(EmployeeStatusTransition::query()->sole()->is_applied);
        } finally {
            if ($advisoryLocked) {
                DB::statement('SELECT pg_advisory_unlock(?)', [self::ADVISORY_LOCK_KEY]);
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    /** Dua worker yang sudah membaca row due hanya boleh menjumlahkan satu mutasi nyata. */
    public function test_dua_worker_apply_due_menghasilkan_total_counter_satu_dan_side_effect_tunggal(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $this->actingAs($actor);
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $status = RefStatusPegawai::query()->where('kode', 'MUTASI')->firstOrFail();
        $tanggalEfektif = now('Asia/Makassar')->subDay()->toDateString();
        $service = app(EmployeeStatusTransitionService::class);
        $transition = $service->schedule(
            $employee,
            $status,
            $tanggalEfektif,
            EmployeeStatusTransition::KIND_STATUS,
            'Mutasi paralel.',
            actorContext: $this->actorContext($actor),
        );
        // Kedua snapshot dibaca sebelum worker pertama menerapkan transisi. Ini
        // mereproduksi deterministik dua worker yang sudah lolos query due yang sama.
        $workerPertama = EmployeeStatusTransition::query()->findOrFail($transition->id);
        $workerKedua = EmployeeStatusTransition::query()->findOrFail($transition->id);
        $applyOne = new \ReflectionMethod($service, 'applyOne');

        $counters = collect([
            $applyOne->invoke($service, $workerPertama),
            $applyOne->invoke($service, $workerKedua),
        ]);

        $this->assertSame(1, $counters->filter()->count(), $counters->toJson());
        $this->assertTrue($transition->refresh()->is_applied);
        $this->assertDatabaseCount('employee_status_histories', 1);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employee->id)
            ->count());
        $this->assertSame(1, SimpegNotification::query()->where('user_id', $employee->id)->count());

        // Migration historis fail-closed melarang rollback schema ketika audit masih
        // berisi data; cleanup hanya untuk lifecycle DatabaseMigrations pada test ini.
        DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_append_only');
        try {
            DB::table('audit_logs')->delete();
        } finally {
            DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_append_only');
        }
    }

    /** @return array{barrier:string,ready_0:string,ready_1:string,result_0:string,result_1:string} */
    private function racePaths(): array
    {
        $directory = storage_path('framework/testing/status-transition-race-'.Str::uuid());
        $this->raceDirectory = $directory;
        File::ensureDirectoryExists($directory);

        return [
            'barrier' => $directory.'/go',
            'ready_0' => $directory.'/ready-0',
            'ready_1' => $directory.'/ready-1',
            'result_0' => $directory.'/result-0.json',
            'result_1' => $directory.'/result-1.json',
        ];
    }

    /** @param array<string, string> $input */
    private function worker(array $input): Process
    {
        return new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/EmployeeStatusTransitionRaceWorker.php'),
            base64_encode(json_encode($input, JSON_THROW_ON_ERROR)),
        ], base_path(), timeout: 60);
    }

    /** @param list<int> $pids */
    private function waitForBothWorkersToBlock(array $pids, int $timeoutMilliseconds): bool
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            $blocked = (int) DB::scalar(
                'SELECT COUNT(*) FROM pg_stat_activity WHERE pid IN (?, ?) AND wait_event_type = ?',
                [$pids[0], $pids[1], 'Lock'],
            );
            if ($blocked === 2) {
                return true;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /** @param list<int> $pids */
    private function waitingAdvisoryLockCount(array $pids): int
    {
        return (int) DB::scalar(
            "SELECT COUNT(*) FROM pg_locks WHERE pid IN (?, ?) AND locktype = 'advisory' AND granted = false",
            [$pids[0], $pids[1]],
        );
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

    /** Provenance eksplisit untuk schedule internal pada test concurrency. */
    private function actorContext(User $actor): EmployeeStatusActorContext
    {
        $request = Request::create('/internal/status-schedule', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'SIMPEG-Concurrency-Test/1.0',
        ]);
        $request->setUserResolver(static fn (): User => $actor);

        return EmployeeStatusActorContext::capture(
            $request,
            'employees.deactivate',
            EmployeeStatusTransition::KIND_STATUS,
        );
    }

    /** Trigger test menahan kedua INSERT setelah constraint FK mengambil key-share employee. */
    private function installDocumentBarrier(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION status_document_test_barrier()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
BEGIN
    IF NEW.jenis_dokumen = 'sk_status_pegawai' THEN
        PERFORM pg_advisory_xact_lock_shared(19082701);
    END IF;

    RETURN NEW;
END;
$function$;

CREATE TRIGGER zz_status_document_test_barrier
AFTER INSERT ON documents
FOR EACH ROW
EXECUTE FUNCTION status_document_test_barrier();
SQL);
    }
}
