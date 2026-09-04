<?php

namespace Tests\Feature;

use App\Actions\Employees\ChangeEmployeeStatusAction;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStatusTransition;
use App\Models\Permission;
use App\Models\RefStatusPegawai;
use App\Models\Role;
use App\Models\User;
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
class EmployeeStatusTargetLockConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const WORKER_READY_TIMEOUT_MILLISECONDS = 60_000;

    private const WORKER_LOCK_TIMEOUT_MILLISECONDS = 15_000;

    private const WORKER_TIMEOUT_SECONDS = 120;

    private ?string $raceDirectory = null;

    protected function setUp(): void
    {
        $driver = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Race target status wajib diuji pada PostgreSQL.');
        }

        parent::setUp();
        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);

        // Aktor sengaja hanya punya update. Reklasifikasi target menjadi nonaktif
        // harus mengubah permission yang diperlukan menjadi deactivate.
        $role = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $deactivate = Permission::query()->where('name', 'employees.deactivate')->firstOrFail();
        $role->permissions()->detach($deactivate->id);
    }

    protected function tearDown(): void
    {
        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        // Worker control-plane membuat audit committed di luar transaksi test.
        // Kosongkan ledger append-only sebelum DatabaseMigrations menurunkan schema.
        if (DB::getSchemaBuilder()->hasTable('audit_logs')) {
            DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_append_only');
            try {
                DB::table('audit_logs')->delete();
            } finally {
                DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_append_only');
            }
        }

        parent::tearDown();
    }

    public function test_immediate_menunggu_reklasifikasi_target_lalu_memakai_permission_terbaru(): void
    {
        $this->assertLifecycleWaitsForTarget(now('Asia/Makassar')->toDateString());
    }

    public function test_schedule_menunggu_reklasifikasi_target_sebelum_membekukan_provenance(): void
    {
        $this->assertLifecycleWaitsForTarget(now('Asia/Makassar')->addDay()->toDateString());
    }

    public function test_immediate_memakai_identitas_target_terkunci_untuk_dokumen_snapshot_dan_histori(): void
    {
        $this->assertLifecycleUsesLockedTargetIdentity(now('Asia/Makassar')->toDateString(), false);
    }

    public function test_schedule_memakai_identitas_target_terkunci_untuk_dokumen_snapshot_dan_histori_due(): void
    {
        $this->assertLifecycleUsesLockedTargetIdentity(now('Asia/Makassar')->addDay()->toDateString(), true);
    }

    public function test_apply_due_menunggu_target_dan_menolak_klasifikasi_baru_yang_tidak_cocok_provenance(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $sentinel = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $status = RefStatusPegawai::create([
            'kode' => 'TARGET_DUE_'.Str::upper(Str::random(6)),
            'nama' => 'Target Due '.Str::random(6),
            'kelompok' => 'Aktif/khusus',
            'is_active' => true,
        ]);
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();

        app(ChangeEmployeeStatusAction::class)->execute($employee, [
            'status_pegawai_id' => $status->id,
            'tanggal' => $tanggal,
            'keterangan' => 'Provenance update dibekukan sebelum reklasifikasi due.',
        ], $this->requestFor($actor));

        $transition = EmployeeStatusTransition::query()->sole();
        $this->assertSame('employees.update', $transition->authorization_permission);
        $paths = $this->racePaths();
        $updater = $this->controlPlaneWorker([
            'mode' => 'force_reclassification',
            'status_id' => $status->id,
            'kelompok' => 'Nonaktif',
            'ready' => $paths['update_ready'],
            'updated' => $paths['updated'],
            'release_update' => $paths['release_update'],
            'result' => $paths['update_result'],
        ]);
        $due = $this->applyDueWorker([
            'sentinel_actor_id' => $sentinel->id,
            'tanggal' => $tanggal,
            'ready' => $paths['due_ready'],
            'result' => $paths['due_result'],
        ]);

        try {
            $updater->start();
            $this->assertTrue($this->waitFor($paths['updated'], self::WORKER_READY_TIMEOUT_MILLISECONDS));
            $due->start();
            $this->assertTrue($this->waitFor($paths['due_ready'], self::WORKER_READY_TIMEOUT_MILLISECONDS));
            $duePid = (int) File::get($paths['due_ready']);

            $this->assertTrue(
                $this->waitForLock($duePid, self::WORKER_LOCK_TIMEOUT_MILLISECONDS),
                'Apply due wajib menunggu row lock target sebelum membandingkan provenance.',
            );
            $this->assertFalse(File::exists($paths['due_result']));

            File::put($paths['release_update'], 'release');
            $updater->wait();
            $due->wait();

            $this->assertTrue($updater->isSuccessful(), $updater->getErrorOutput());
            $this->assertTrue($due->isSuccessful(), $due->getErrorOutput());
            $result = json_decode(File::get($paths['due_result']), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['ok']);
            $this->assertSame(0, $result['applied']);
            $this->assertSame($sentinel->id, $result['before_actor_id']);
            $this->assertSame($sentinel->id, $result['after_actor_id']);
            $this->assertSame('Nonaktif', $status->refresh()->kelompok);
            $this->assertFalse($transition->refresh()->is_applied);
            $this->assertTrue($employee->refresh()->isActive());
            $this->assertDatabaseCount('employee_status_histories', 0);
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertDatabaseCount('notifications', 0);
        } finally {
            File::put($paths['release_update'], 'release');
            foreach ([$updater, $due] as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }
    }

    private function assertLifecycleWaitsForTarget(string $tanggal): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $status = RefStatusPegawai::create([
            'kode' => 'TARGET_RACE_'.Str::upper(Str::random(6)),
            'nama' => 'Target Race '.Str::random(6),
            'kelompok' => 'Aktif/khusus',
            'is_active' => true,
        ]);
        $paths = $this->racePaths();
        File::put($paths['upload'], '%PDF-1.4 target race');

        $updater = $this->controlPlaneWorker([
            'mode' => 'update_classification',
            'actor_id' => User::factory()->superAdmin()->create()->id,
            'status_id' => $status->id,
            'kelompok' => 'Nonaktif',
            'ready' => $paths['update_ready'],
            'updated' => $paths['updated'],
            'release_update' => $paths['release_update'],
            'result' => $paths['update_result'],
        ]);
        $lifecycle = $this->lifecycleWorker([
            'actor_id' => $actor->id,
            'employee_id' => $employee->id,
            'status_id' => $status->id,
            'tanggal' => $tanggal,
            'file' => $paths['upload'],
            'ready' => $paths['lifecycle_ready'],
            'result' => $paths['lifecycle_result'],
        ]);

        try {
            $updater->start();
            $this->assertTrue($this->waitFor($paths['updated'], self::WORKER_READY_TIMEOUT_MILLISECONDS));

            $lifecycle->start();
            $this->assertTrue($this->waitFor($paths['lifecycle_ready'], self::WORKER_READY_TIMEOUT_MILLISECONDS));
            $lifecyclePid = (int) File::get($paths['lifecycle_ready']);

            $this->assertTrue(
                $this->waitForLock($lifecyclePid, self::WORKER_LOCK_TIMEOUT_MILLISECONDS),
                'Lifecycle wajib menunggu row lock target status sebelum klasifikasi/permission/factory.',
            );
            $this->assertFalse(File::exists($paths['lifecycle_result']));

            File::put($paths['release_update'], 'release');
            $updater->wait();
            $lifecycle->wait();

            $this->assertTrue($updater->isSuccessful(), $updater->getErrorOutput());
            $this->assertTrue($lifecycle->isSuccessful(), $lifecycle->getErrorOutput());

            $result = json_decode(File::get($paths['lifecycle_result']), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($result['ok']);
            $this->assertSame(ValidationException::class, $result['class']);
            $this->assertTrue(
                isset($result['errors']['status_pegawai_id']) || isset($result['errors']['actor']),
                'Penolakan wajib berasal dari klasifikasi terbaru atau permission yang sesuai.',
            );
            $this->assertSame('Nonaktif', $status->refresh()->kelompok);
            $this->assertTrue($employee->refresh()->isActive());
            $this->assertDatabaseCount('employee_status_transitions', 0);
            $this->assertDatabaseCount('employee_status_histories', 0);
            $this->assertDatabaseCount('documents', 0);
            $this->assertDatabaseCount('audit_logs', 1); // Audit control-plane saja.
            $this->assertSame([], Storage::disk(Document::STORAGE_DISK)->allFiles($employee->id));
        } finally {
            File::put($paths['release_update'], 'release');
            foreach ([$updater, $lifecycle] as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            Storage::disk(Document::STORAGE_DISK)->deleteDirectory($employee->id);
        }
    }

    private function assertLifecycleUsesLockedTargetIdentity(string $tanggal, bool $scheduled): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $status = RefStatusPegawai::create([
            'kode' => 'TARGET_LAMA_'.Str::upper(Str::random(5)),
            'nama' => 'Target Lama '.Str::random(5),
            'kelompok' => 'Aktif/khusus',
            'is_active' => true,
        ]);
        $newCode = 'TARGET_BARU_'.Str::upper(Str::random(5));
        $newName = 'Target Baru '.Str::random(5);
        $paths = $this->racePaths();
        File::put($paths['upload'], '%PDF-1.4 target identity race');

        $updater = $this->controlPlaneWorker([
            'mode' => 'update_identity',
            'actor_id' => User::factory()->superAdmin()->create()->id,
            'status_id' => $status->id,
            'kelompok' => 'Aktif/khusus',
            'kode' => $newCode,
            'nama' => $newName,
            'ready' => $paths['update_ready'],
            'updated' => $paths['updated'],
            'release_update' => $paths['release_update'],
            'result' => $paths['update_result'],
        ]);
        $lifecycle = $this->lifecycleWorker([
            'actor_id' => $actor->id,
            'employee_id' => $employee->id,
            'status_id' => $status->id,
            'tanggal' => $tanggal,
            'file' => $paths['upload'],
            'ready' => $paths['lifecycle_ready'],
            'result' => $paths['lifecycle_result'],
        ]);

        try {
            $updater->start();
            $this->assertTrue($this->waitFor($paths['updated'], self::WORKER_READY_TIMEOUT_MILLISECONDS));
            $lifecycle->start();
            $this->assertTrue($this->waitFor($paths['lifecycle_ready'], self::WORKER_READY_TIMEOUT_MILLISECONDS));
            $lifecyclePid = (int) File::get($paths['lifecycle_ready']);
            $this->assertTrue($this->waitForLock($lifecyclePid, self::WORKER_LOCK_TIMEOUT_MILLISECONDS));

            File::put($paths['release_update'], 'release');
            $updater->wait();
            $lifecycle->wait();

            $this->assertTrue($updater->isSuccessful(), $updater->getErrorOutput());
            $this->assertTrue($lifecycle->isSuccessful(), $lifecycle->getErrorOutput());
            $result = json_decode(File::get($paths['lifecycle_result']), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['ok'], (string) ($result['message'] ?? 'Lifecycle gagal.'));

            if ($scheduled) {
                $this->assertSame(1, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
            }

            $document = Document::query()->sole();
            $history = EmployeeStatusHistory::query()->sole();
            $this->assertSame('SK Perubahan Status — '.$newName, $document->nama_dokumen);
            $this->assertStringStartsWith('SK-'.$newCode.'-', (string) $document->nomor_dokumen);
            $this->assertSame($newName, $employee->refresh()->status_aktif);
            $this->assertSame($newName, $history->status_nama);
            $this->assertSame($document->nomor_dokumen, $history->nomor_berkas);
            $this->assertSame($document->file_path, $history->file_sk);
            $this->assertDatabaseCount('audit_logs', 2); // Control-plane dan lifecycle.
        } finally {
            File::put($paths['release_update'], 'release');
            foreach ([$updater, $lifecycle] as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            Storage::disk(Document::STORAGE_DISK)->deleteDirectory($employee->id);
        }
    }

    /** @return array<string, string> */
    private function racePaths(): array
    {
        $directory = storage_path('framework/testing/employee-status-target-race-'.Str::uuid());
        $this->raceDirectory = $directory;
        File::ensureDirectoryExists($directory);

        return [
            'update_ready' => $directory.'/update-ready',
            'updated' => $directory.'/updated',
            'release_update' => $directory.'/release-update',
            'update_result' => $directory.'/update-result.json',
            'lifecycle_ready' => $directory.'/lifecycle-ready',
            'lifecycle_result' => $directory.'/lifecycle-result.json',
            'due_ready' => $directory.'/due-ready',
            'due_result' => $directory.'/due-result.json',
            'upload' => $directory.'/target-race.pdf',
        ];
    }

    /** @param array<string, string> $input */
    private function controlPlaneWorker(array $input): Process
    {
        return new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/ReferenceStatusControlPlaneRaceWorker.php'),
            base64_encode(json_encode($input, JSON_THROW_ON_ERROR)),
        ], base_path(), timeout: self::WORKER_TIMEOUT_SECONDS);
    }

    /** @param array<string, string> $input */
    private function lifecycleWorker(array $input): Process
    {
        return new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/EmployeeStatusTargetRaceWorker.php'),
            base64_encode(json_encode($input, JSON_THROW_ON_ERROR)),
        ], base_path(), timeout: self::WORKER_TIMEOUT_SECONDS);
    }

    /** @param array<string, string> $input */
    private function applyDueWorker(array $input): Process
    {
        return new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/EmployeeStatusApplyDueRaceWorker.php'),
            base64_encode(json_encode($input, JSON_THROW_ON_ERROR)),
        ], base_path(), timeout: self::WORKER_TIMEOUT_SECONDS);
    }

    private function requestFor(User $actor): Request
    {
        $request = Request::create('/uji-race-due-target', 'POST', [], [], [], [
            'REMOTE_ADDR' => '10.27.0.9',
            'HTTP_USER_AGENT' => 'SIMPEG-Target-Due-Race/1.0',
        ]);
        $request->setUserResolver(static fn (): User => $actor);

        return $request;
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

    private function waitForLock(int $pid, int $timeoutMilliseconds): bool
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
        do {
            if ((bool) DB::scalar(
                "select exists(select 1 from pg_stat_activity where pid = ? and wait_event_type = 'Lock')",
                [$pid],
            )) {
                return true;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }
}
