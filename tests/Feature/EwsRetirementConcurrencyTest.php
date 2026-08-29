<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStatusTransition;
use App\Models\EwsAlert;
use App\Models\RefStatusPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Membuktikan serialisasi approval pensiun dan rollback provenance pada PostgreSQL. */
#[Group('serial')]
class EwsRetirementConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = '2026_08_28_000002_add_ews_source_to_employee_status_transitions.php';

    private ?string $raceDirectory = null;

    protected function setUp(): void
    {
        $driver = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Race pensiun EWS wajib diuji pada PostgreSQL.');
        }

        parent::setUp();
        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);

        $this->raceDirectory = storage_path('framework/testing/ews-retirement-race-'.Str::uuid());
        File::ensureDirectoryExists($this->raceDirectory);
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && Schema::hasTable('employee_status_transitions')) {
            DB::table('employee_status_transitions')->delete();
        }

        if ($this->app !== null && Schema::hasTable('audit_logs')) {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_append_only_truncate ON audit_logs');
            AuditLog::query()->delete();
        }

        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        parent::tearDown();
    }

    /** Approval yang commit sesudah snapshot chunk harus menang sebelum INSERT alert pensiun. */
    public function test_engine_rechecks_pending_retirement_after_stale_chunk_snapshot(): void
    {
        $this->travelTo(now('Asia/Makassar')->startOfSecond());
        Storage::fake(Document::STORAGE_DISK);
        $actor = User::factory()->superAdmin()->create();
        $this->actingAs($actor);
        $effectiveDate = now('Asia/Makassar')->addDays(90)->toDateString();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => $effectiveDate,
            'tanggal_kgb_berikutnya' => now('Asia/Makassar')->addDays(60)->toDateString(),
        ]);
        $source = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 180);
        $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 365);
        $unrelated = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => $effectiveDate,
        ]);
        $paths = $this->racePaths('engine');
        $process = $this->worker('EwsRetirementEngineRaceWorker.php', [
            'employee_id' => $employee->id,
            'now' => now()->toIso8601String(),
            ...$paths,
        ]);

        try {
            $process->start();
            $this->assertTrue($this->waitForFile($paths['ready']), 'Engine tidak mencapai barrier setelah snapshot chunk.');
            $this->assertFalse(EmployeeStatusTransition::query()->exists());

            $this->actingAs($actor)
                ->withSession(['_token' => 'test-token'])
                ->postJson(route('ews.followup.update', $source), [
                    '_token' => 'test-token',
                    'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                    'handled_note' => 'Approval future menang pada race engine.',
                    'no_sk' => 'SK-PENSIUN-RACE-ENGINE',
                    'tanggal_sk' => $effectiveDate,
                    'file_sk' => UploadedFile::fake()->createWithContent('sk-pensiun-race.pdf', 'SK pensiun race'),
                ])
                ->assertOk();
            $this->assertDatabaseHas('employee_status_transitions', [
                'employee_id' => $employee->id,
                'kind' => EmployeeStatusTransition::KIND_EWS_RETIREMENT,
                'is_applied' => false,
            ]);

            File::put($paths['release'], 'continue');
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $this->processDiagnostic($process, $paths['result']));
            $outcome = $this->readJson($paths['result']);
            $this->assertTrue((bool) ($outcome['ok'] ?? false), json_encode($outcome));

            $this->assertDatabaseMissing('ews_alerts', [
                'employee_id' => $employee->id,
                'type' => 'PENSIUN',
                'interval_days' => 90,
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
            ]);
            $this->assertDatabaseHas('ews_alerts', [
                'employee_id' => $employee->id,
                'type' => 'KGB',
                'interval_days' => 60,
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
            ]);
            $this->assertDatabaseHas('ews_alerts', [
                'employee_id' => $unrelated->id,
                'type' => 'PENSIUN',
                'interval_days' => 90,
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
            ]);
        } finally {
            File::put($paths['release'], 'cleanup');
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    /** Approval efektif hari ini harus membatalkan snapshot aktif engine yang sudah usang. */
    public function test_engine_rechecks_employee_is_still_active_after_immediate_retirement_commits(): void
    {
        $this->travelTo(now('Asia/Makassar')->startOfSecond());
        Storage::fake(Document::STORAGE_DISK);
        $actor = User::factory()->superAdmin()->create();
        $this->actingAs($actor);
        $targetDate = now('Asia/Makassar')->addDays(90)->toDateString();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => $targetDate,
        ]);
        $source = $this->activeAlertFor($employee, 'PENSIUN', $targetDate, 180);
        $paths = $this->racePaths('engine-immediate-retirement');
        $process = $this->worker('EwsRetirementEngineRaceWorker.php', [
            'employee_id' => $employee->id,
            'now' => now()->toIso8601String(),
            ...$paths,
        ]);

        try {
            $process->start();
            $this->assertTrue($this->waitForFile($paths['ready']), 'Engine tidak mencapai barrier setelah snapshot aktif.');

            $this->actingAs($actor)
                ->withSession(['_token' => 'test-token'])
                ->postJson(route('ews.followup.update', $source), [
                    '_token' => 'test-token',
                    'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                    'handled_note' => 'Pensiun efektif hari ini menang pada race engine.',
                    'no_sk' => 'SK-PENSIUN-RACE-IMMEDIATE',
                    'tanggal_sk' => now('Asia/Makassar')->toDateString(),
                    'file_sk' => UploadedFile::fake()->createWithContent(
                        'sk-pensiun-race-immediate.pdf',
                        'SK pensiun efektif hari ini',
                    ),
                ])
                ->assertOk();
            $this->assertFalse($employee->refresh()->isActive());
            $this->assertDatabaseMissing('employee_status_transitions', [
                'employee_id' => $employee->id,
                'kind' => EmployeeStatusTransition::KIND_EWS_RETIREMENT,
                'is_applied' => false,
            ]);

            File::put($paths['release'], 'continue');
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $this->processDiagnostic($process, $paths['result']));
            $outcome = $this->readJson($paths['result']);
            $this->assertTrue((bool) ($outcome['ok'] ?? false), json_encode($outcome));

            $this->assertDatabaseMissing('ews_alerts', [
                'employee_id' => $employee->id,
                'type' => 'PENSIUN',
                'interval_days' => 90,
            ]);
            $this->assertDatabaseMissing('notifications', [
                'user_id' => $employee->id,
                'type' => 'ews.pensiun',
            ]);
        } finally {
            File::put($paths['release'], 'cleanup');
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    /** Scheduler dan approval pada alert sama harus mengikuti urutan lock alert lalu pegawai. */
    public function test_engine_first_existing_alert_and_http_followup_finish_without_deadlock(): void
    {
        $this->travelTo(now('Asia/Makassar')->startOfSecond());
        $actor = User::factory()->superAdmin()->create();
        $this->actingAs($actor);
        $effectiveDate = now('Asia/Makassar')->addDays(90)->toDateString();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => $effectiveDate,
        ]);
        $source = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 90);
        $enginePaths = $this->racePaths('engine-first-lock');
        $followupPaths = $this->racePaths('http-followup-lock');
        $engine = $this->worker('EwsRetirementEngineRaceWorker.php', [
            'employee_id' => $employee->id,
            'now' => now()->toIso8601String(),
            'barrier_stage' => 'after_first_lock',
            ...$enginePaths,
        ]);
        $followup = $this->worker('EwsRetirementFollowupRaceWorker.php', [
            'actor_id' => $actor->id,
            'alert_id' => $source->id,
            'effective_date' => $effectiveDate,
            'storage_root' => $this->raceDirectory.'/documents',
            'now' => now()->toIso8601String(),
            ...$followupPaths,
        ]);
        $engineBarrier = [];
        $followupRaceState = null;
        $engineOutcome = [];
        $followupOutcome = [];

        try {
            $engine->start();
            $this->assertTrue($this->waitForFile($enginePaths['ready']), 'Engine tidak memperoleh lock pertama.');
            $engineBarrier = $this->readJson($enginePaths['ready']);

            $followup->start();
            $this->assertTrue($this->waitForFile($followupPaths['ready']), 'Worker follow-up tidak menulis PID.');
            $followupPid = (int) ($this->readJson($followupPaths['ready'])['pid'] ?? 0);
            $followupRaceState = $this->waitForLockOrCompletion($followup, $followupPid);

            File::put($enginePaths['release'], 'continue');
            $engine->wait();
            $followup->wait();
            $engineOutcome = $this->readJson($enginePaths['result']);
            $followupOutcome = $this->readJson($followupPaths['result']);
        } finally {
            File::put($enginePaths['release'], 'cleanup');
            foreach ([$engine, $followup] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }

        $diagnostic = json_encode([
            'engine_barrier' => $engineBarrier,
            'followup_state' => $followupRaceState,
            'engine' => $engineOutcome,
            'followup' => $followupOutcome,
            'engine_process' => $this->processDiagnostic($engine, $enginePaths['result']),
            'followup_process' => $this->processDiagnostic($followup, $followupPaths['result']),
        ], JSON_THROW_ON_ERROR);

        $this->assertSame('alert', $engineBarrier['stage'] ?? null, $diagnostic);
        $this->assertSame('blocked', $followupRaceState, $diagnostic);
        $this->assertTrue((bool) ($engineOutcome['ok'] ?? false), $diagnostic);
        $this->assertTrue((bool) ($followupOutcome['ok'] ?? false), $diagnostic);
        $this->assertDatabaseHas('ews_alerts', [
            'id' => $source->id,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
        ]);
        $this->assertSame(1, EwsAlert::query()
            ->where('employee_id', $employee->id)
            ->where('type', 'PENSIUN')
            ->whereDate('target_date', $effectiveDate)
            ->where('interval_days', 90)
            ->count());
        $this->assertSame(1, EmployeeStatusTransition::query()
            ->where('employee_id', $employee->id)
            ->where('kind', EmployeeStatusTransition::KIND_EWS_RETIREMENT)
            ->where('source_ews_alert_id', $source->id)
            ->count());
        $this->assertSame(1, Document::query()->where('employee_id', $employee->id)->count());
        $this->assertCount(1, File::allFiles($this->raceDirectory.'/documents'));
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employee->id)
            ->count());
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', 'EwsAlert')
            ->where('auditable_id', $source->id)
            ->where('event', 'UPDATE')
            ->count());
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'ews.followup.pensiun')
            ->count());
        $this->assertSame(0, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'status_pegawai.dinonaktifkan')
            ->count());
        $this->assertNotNull($source->refresh()->followup_notified_at);
        $this->assertNull($source->lifecycle_notified_at);
        $this->assertSame('Aktif', $employee->refresh()->status_aktif);
    }

    /** Engine dan mark-as-read simulasi harus sama-sama mengikuti alert lalu notifikasi. */
    public function test_engine_and_simulated_role_mark_as_read_finish_without_alert_notification_deadlock(): void
    {
        $this->travelTo(now('Asia/Makassar')->startOfSecond());
        $targetDate = now('Asia/Makassar')->addDays(90)->toDateString();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => $targetDate,
        ]);
        $actor = User::factory()->superAdmin()->create([
            'employee_id' => $employee->id,
            'temporary_role' => 'admin_kepegawaian',
            'temporary_role_started_at' => now(),
        ]);
        $actor->forceFill(['temporary_role_switched_by' => $actor->id])->save();
        $alert = $this->activeAlertFor($employee, 'PENSIUN', $targetDate, 90);
        $notification = SimpegNotification::query()->create([
            'user_id' => $employee->id,
            'ews_alert_id' => $alert->id,
            'type' => 'ews.pensiun',
            'title' => 'Peringatan EWS: Pensiun',
            'body' => 'Reminder pensiun sebelum race.',
            'data' => ['ews_alert_id' => $alert->id],
            'is_read' => false,
        ]);
        $enginePaths = $this->racePaths('engine-alert-notification');
        $readerPaths = $this->racePaths('reader-alert-notification');
        $notificationLocked = $this->raceDirectory.'/notification-row-locked';
        $notificationRelease = $this->raceDirectory.'/notification-row-release';
        $engine = $this->worker('EwsRetirementEngineRaceWorker.php', [
            'employee_id' => $employee->id,
            'now' => now()->toIso8601String(),
            'barrier_stage' => 'after_first_lock',
            ...$enginePaths,
        ]);
        $reader = $this->worker('EwsNotificationMarkReadRaceWorker.php', [
            'actor_id' => $actor->id,
            'notification_id' => $notification->id,
            'notification_locked' => $notificationLocked,
            'notification_release' => $notificationRelease,
            'now' => now()->toIso8601String(),
            ...$readerPaths,
        ]);
        $readerState = null;
        $engineWaitState = null;
        $engineOutcome = [];
        $readerOutcome = [];

        try {
            $engine->start();
            $this->assertTrue($this->waitForFile($enginePaths['ready']), 'Engine tidak memperoleh lock alert.');
            $enginePid = (int) ($this->readJson($enginePaths['ready'])['pid'] ?? 0);

            $reader->start();
            $this->assertTrue($this->waitForFile($readerPaths['ready']), 'Worker mark-as-read tidak menulis PID.');
            $readerPid = (int) ($this->readJson($readerPaths['ready'])['pid'] ?? 0);
            $readerState = $this->waitForFileOrDatabaseLock($reader, $readerPid, $notificationLocked);

            if ($readerState === 'notification_locked') {
                File::put($enginePaths['release'], 'continue');
                $engineWaitState = $this->waitForLockOrCompletion($engine, $enginePid);
                File::put($notificationRelease, 'continue');
            } else {
                // Implementasi alert-first menunggu alert tanpa pernah memegang notification.
                File::put($notificationRelease, 'continue');
                File::put($enginePaths['release'], 'continue');
            }

            $engine->wait();
            $reader->wait();
            $engineOutcome = $this->readJson($enginePaths['result']);
            $readerOutcome = $this->readJson($readerPaths['result']);
        } finally {
            File::put($notificationRelease, 'cleanup');
            File::put($enginePaths['release'], 'cleanup');
            foreach ([$engine, $reader] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }

        $diagnostic = json_encode([
            'reader_state' => $readerState,
            'engine_wait_state' => $engineWaitState,
            'engine' => $engineOutcome,
            'reader' => $readerOutcome,
            'engine_process' => $this->processDiagnostic($engine, $enginePaths['result']),
            'reader_process' => $this->processDiagnostic($reader, $readerPaths['result']),
        ], JSON_THROW_ON_ERROR);

        $this->assertSame('blocked_on_alert', $readerState, $diagnostic);
        if ($readerState === 'notification_locked') {
            $this->assertSame('blocked', $engineWaitState, $diagnostic);
        }
        $this->assertTrue((bool) ($engineOutcome['ok'] ?? false), $diagnostic);
        $this->assertTrue((bool) ($readerOutcome['ok'] ?? false), $diagnostic);
        $this->assertTrue($notification->refresh()->is_read, $diagnostic);
        $this->assertNotNull($alert->refresh()->notification_acknowledged_at, $diagnostic);
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('ews_alert_id', $alert->id)
            ->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'ROLE_SIMULATION_USAGE',
            'user_id' => $actor->id,
        ]);
    }

    /** Engine pada sibling lain tidak boleh deadlock dengan alert pilihan HTTP. */
    public function test_engine_existing_sibling_and_http_followup_finish_without_deadlock(): void
    {
        $this->travelTo(now('Asia/Makassar')->startOfSecond());
        $actor = User::factory()->superAdmin()->create();
        $this->actingAs($actor);
        $effectiveDate = now('Asia/Makassar')->addDays(90)->toDateString();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => $effectiveDate,
        ]);
        $selected = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 180);
        $engineTarget = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 90);
        $enginePaths = $this->racePaths('engine-sibling-lock');
        $followupPaths = $this->racePaths('http-selected-sibling-lock');
        $engine = $this->worker('EwsRetirementEngineRaceWorker.php', [
            'employee_id' => $employee->id,
            'now' => now()->toIso8601String(),
            'barrier_stage' => 'after_first_lock',
            ...$enginePaths,
        ]);
        $followup = $this->worker('EwsRetirementFollowupRaceWorker.php', [
            'actor_id' => $actor->id,
            'alert_id' => $selected->id,
            'effective_date' => $effectiveDate,
            'storage_root' => $this->raceDirectory.'/sibling-documents',
            'now' => now()->toIso8601String(),
            ...$followupPaths,
        ]);
        $engineBarrier = [];
        $followupRaceState = null;
        $engineOutcome = [];
        $followupOutcome = [];

        try {
            $engine->start();
            $this->assertTrue($this->waitForFile($enginePaths['ready']), 'Engine tidak memperoleh lock sibling target.');
            $engineBarrier = $this->readJson($enginePaths['ready']);

            $followup->start();
            $this->assertTrue($this->waitForFile($followupPaths['ready']), 'Worker follow-up sibling tidak menulis PID.');
            $followupPid = (int) ($this->readJson($followupPaths['ready'])['pid'] ?? 0);
            $followupRaceState = $this->waitForLockOrCompletion($followup, $followupPid);

            File::put($enginePaths['release'], 'continue');
            $engine->wait();
            $followup->wait();
            $engineOutcome = $this->readJson($enginePaths['result']);
            $followupOutcome = $this->readJson($followupPaths['result']);
        } finally {
            File::put($enginePaths['release'], 'cleanup');
            foreach ([$engine, $followup] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }

        $diagnostic = json_encode([
            'selected' => $selected->id,
            'engine_target' => $engineTarget->id,
            'engine_barrier' => $engineBarrier,
            'followup_state' => $followupRaceState,
            'engine' => $engineOutcome,
            'followup' => $followupOutcome,
            'engine_process' => $this->processDiagnostic($engine, $enginePaths['result']),
            'followup_process' => $this->processDiagnostic($followup, $followupPaths['result']),
        ], JSON_THROW_ON_ERROR);

        $this->assertSame('alert', $engineBarrier['stage'] ?? null, $diagnostic);
        $this->assertSame('blocked', $followupRaceState, $diagnostic);
        $this->assertTrue((bool) ($engineOutcome['ok'] ?? false), $diagnostic);
        $this->assertTrue((bool) ($followupOutcome['ok'] ?? false), $diagnostic);
        $this->assertSame(1, EmployeeStatusTransition::query()
            ->where('employee_id', $employee->id)
            ->where('kind', EmployeeStatusTransition::KIND_EWS_RETIREMENT)
            ->where('source_ews_alert_id', $selected->id)
            ->count());
        $this->assertSame(1, Document::query()->where('employee_id', $employee->id)->count());
        $this->assertCount(1, File::allFiles($this->raceDirectory.'/sibling-documents'));
        $this->assertDatabaseHas('ews_alerts', [
            'id' => $selected->id,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'followup_group_id' => $selected->id,
        ]);
        $this->assertDatabaseHas('ews_alerts', [
            'id' => $engineTarget->id,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'followup_group_id' => $selected->id,
        ]);
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', 'EwsAlert')
            ->where('auditable_id', $selected->id)
            ->where('event', 'UPDATE')
            ->count());
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'ews.followup.pensiun')
            ->count());
        $this->assertSame('Aktif', $employee->refresh()->status_aktif);
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $employee->id)->count());
    }

    /** Dua HTTP sibling harus mengambil set alert dalam urutan sama tanpa deadlock. */
    public function test_two_http_followups_lock_siblings_in_same_order(): void
    {
        $this->travelTo(now('Asia/Makassar')->startOfSecond());
        $firstActor = User::factory()->superAdmin()->create();
        $secondActor = User::factory()->superAdmin()->create();
        $this->actingAs($firstActor);
        $this->actingAs($secondActor);
        $effectiveDate = now('Asia/Makassar')->addDays(90)->toDateString();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => $effectiveDate,
        ]);
        $firstAlert = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 180);
        $secondAlert = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 90);
        $firstPaths = $this->racePaths('http-first-sibling');
        $secondPaths = $this->racePaths('http-second-sibling');
        $firstLockReady = $this->raceDirectory.'/http-first-sibling-lock';
        $first = $this->worker('EwsRetirementFollowupRaceWorker.php', [
            'actor_id' => $firstActor->id,
            'alert_id' => $firstAlert->id,
            'effective_date' => $effectiveDate,
            'storage_root' => $this->raceDirectory.'/http-first-documents',
            'now' => now()->toIso8601String(),
            'lock_ready' => $firstLockReady,
            ...$firstPaths,
        ]);
        $second = $this->worker('EwsRetirementFollowupRaceWorker.php', [
            'actor_id' => $secondActor->id,
            'alert_id' => $secondAlert->id,
            'effective_date' => $effectiveDate,
            'storage_root' => $this->raceDirectory.'/http-second-documents',
            'now' => now()->toIso8601String(),
            ...$secondPaths,
        ]);
        $secondRaceState = null;
        $firstOutcome = [];
        $secondOutcome = [];

        try {
            $first->start();
            $this->assertTrue($this->waitForFile($firstPaths['ready']), 'HTTP pertama tidak menulis PID.');
            $this->assertTrue($this->waitForFile($firstLockReady), 'HTTP pertama tidak memperoleh lock alert awal.');

            $second->start();
            $this->assertTrue($this->waitForFile($secondPaths['ready']), 'HTTP kedua tidak menulis PID.');
            $secondPid = (int) ($this->readJson($secondPaths['ready'])['pid'] ?? 0);
            $secondRaceState = $this->waitForLockOrCompletion($second, $secondPid);

            File::put($firstPaths['release'], 'continue');
            $first->wait();
            $second->wait();
            $firstOutcome = $this->readJson($firstPaths['result']);
            $secondOutcome = $this->readJson($secondPaths['result']);
        } finally {
            File::put($firstPaths['release'], 'cleanup');
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }

        $diagnostic = json_encode([
            'second_state' => $secondRaceState,
            'first' => $firstOutcome,
            'second' => $secondOutcome,
            'first_process' => $this->processDiagnostic($first, $firstPaths['result']),
            'second_process' => $this->processDiagnostic($second, $secondPaths['result']),
        ], JSON_THROW_ON_ERROR);

        $this->assertSame('blocked', $secondRaceState, $diagnostic);
        $this->assertTrue((bool) ($firstOutcome['ok'] ?? false), $diagnostic);
        $this->assertSame(200, $firstOutcome['status'] ?? null, $diagnostic);
        $this->assertFalse((bool) ($secondOutcome['ok'] ?? true), $diagnostic);
        $this->assertSame(422, $secondOutcome['status'] ?? null, $diagnostic);
        $this->assertStringNotContainsString('40P01', (string) ($firstOutcome['message'] ?? ''), $diagnostic);
        $this->assertStringNotContainsString('40P01', (string) ($secondOutcome['message'] ?? ''), $diagnostic);
        $this->assertSame(1, EmployeeStatusTransition::query()
            ->where('employee_id', $employee->id)
            ->where('kind', EmployeeStatusTransition::KIND_EWS_RETIREMENT)
            ->count());
        $this->assertSame(1, Document::query()->where('employee_id', $employee->id)->count());
        $this->assertCount(1, File::allFiles($this->raceDirectory.'/http-first-documents'));
        $secondStorageRoot = $this->raceDirectory.'/http-second-documents';
        $this->assertSame(0, File::exists($secondStorageRoot) ? count(File::allFiles($secondStorageRoot)) : 0);
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'ews.followup.pensiun')
            ->count());
    }

    /** Selected yang berubah setelah route binding harus gagal sebelum side effect. */
    #[DataProvider('selectedAlertStaleStateProvider')]
    public function test_http_followup_fails_closed_when_selected_changes_before_lock(string $mutation): void
    {
        $this->travelTo(now('Asia/Makassar')->startOfSecond());
        $actor = User::factory()->superAdmin()->create();
        $this->actingAs($actor);
        $effectiveDate = now('Asia/Makassar')->addDays(90)->toDateString();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => $effectiveDate,
        ]);
        $selected = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 180);
        $sibling = $this->activeAlertFor($employee, 'PENSIUN', $effectiveDate, 90);
        $paths = $this->racePaths('http-stale-'.$mutation);
        $readReady = $this->raceDirectory.'/http-stale-'.$mutation.'-read';
        $readRelease = $this->raceDirectory.'/http-stale-'.$mutation.'-release';
        $storageRoot = $this->raceDirectory.'/http-stale-'.$mutation.'-documents';
        $worker = $this->worker('EwsRetirementFollowupRaceWorker.php', [
            'actor_id' => $actor->id,
            'alert_id' => $selected->id,
            'effective_date' => $effectiveDate,
            'storage_root' => $storageRoot,
            'now' => now()->toIso8601String(),
            'read_ready' => $readReady,
            'read_release' => $readRelease,
            ...$paths,
        ]);
        $outcome = [];

        try {
            $worker->start();
            $this->assertTrue($this->waitForFile($paths['ready']), 'HTTP stale tidak menulis PID.');
            $this->assertTrue($this->waitForFile($readReady), 'HTTP tidak berhenti sesudah route binding alert.');

            if ($mutation === 'status') {
                EwsAlert::query()->whereKey($selected->id)->update([
                    'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
                    'followup_group_id' => $selected->id,
                    'is_processed' => true,
                    'handled_at' => now(),
                    'handled_by' => $actor->id,
                    'handled_note' => 'Ditutup proses lain sesudah route binding.',
                ]);
            } else {
                EwsAlert::query()->whereKey($selected->id)->update([
                    'followup_group_id' => $sibling->id,
                ]);
            }

            File::put($readRelease, 'continue');
            $worker->wait();
            $outcome = $this->readJson($paths['result']);
        } finally {
            File::put($readRelease, 'cleanup');
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }

        $diagnostic = $this->processDiagnostic($worker, $paths['result']);
        $this->assertFalse((bool) ($outcome['ok'] ?? true), $diagnostic);
        $this->assertSame(422, $outcome['status'] ?? null, $diagnostic);
        $this->assertSame(0, EmployeeStatusTransition::query()->count());
        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, File::exists($storageRoot) ? count(File::allFiles($storageRoot)) : 0);
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame(0, SimpegNotification::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function selectedAlertStaleStateProvider(): array
    {
        return [
            'status menjadi tertutup' => ['status'],
            'ownership grup berubah' => ['group'],
        ];
    }

    /** Dua snapshot alert kosong harus tetap menghasilkan satu alert dan satu reminder. */
    public function test_two_engines_dedupe_new_retirement_alert_after_both_observe_missing_row(): void
    {
        $this->travelTo(now('Asia/Makassar')->startOfSecond());
        $effectiveDate = now('Asia/Makassar')->addDays(90)->toDateString();
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_pensiun' => $effectiveDate,
        ]);
        $firstPaths = $this->racePaths('new-alert-first');
        $secondPaths = $this->racePaths('new-alert-second');
        $sharedRelease = $this->raceDirectory.'/new-alert-release';
        $first = $this->worker('EwsRetirementEngineRaceWorker.php', [
            'employee_id' => $employee->id,
            'now' => now()->toIso8601String(),
            'barrier_stage' => 'after_alert_miss',
            'ready' => $firstPaths['ready'],
            'release' => $sharedRelease,
            'result' => $firstPaths['result'],
        ]);
        $second = $this->worker('EwsRetirementEngineRaceWorker.php', [
            'employee_id' => $employee->id,
            'now' => now()->toIso8601String(),
            'barrier_stage' => 'after_alert_miss',
            'ready' => $secondPaths['ready'],
            'release' => $sharedRelease,
            'result' => $secondPaths['result'],
        ]);

        try {
            $first->start();
            $second->start();
            $this->assertTrue($this->waitForFile($firstPaths['ready'], 5_000), 'Engine pertama tidak melihat alert kosong.');
            $this->assertTrue($this->waitForFile($secondPaths['ready'], 5_000), 'Engine kedua tidak mencapai snapshot alert kosong yang sama.');

            File::put($sharedRelease, 'continue');
            $first->wait();
            $second->wait();
        } finally {
            File::put($sharedRelease, 'cleanup');
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }

        $firstOutcome = $this->readJson($firstPaths['result']);
        $secondOutcome = $this->readJson($secondPaths['result']);
        $diagnostic = json_encode([
            'first' => $firstOutcome,
            'second' => $secondOutcome,
            'first_process' => $this->processDiagnostic($first, $firstPaths['result']),
            'second_process' => $this->processDiagnostic($second, $secondPaths['result']),
        ], JSON_THROW_ON_ERROR);

        $this->assertTrue((bool) ($firstOutcome['ok'] ?? false), $diagnostic);
        $this->assertTrue((bool) ($secondOutcome['ok'] ?? false), $diagnostic);
        $alert = EwsAlert::query()
            ->where('employee_id', $employee->id)
            ->where('type', 'PENSIUN')
            ->whereDate('target_date', $effectiveDate)
            ->where('interval_days', 90)
            ->sole();
        $this->assertSame(1, SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('ews_alert_id', $alert->id)
            ->count());
    }

    /** Lock rollback harus terlihat memblokir writer sebelum keputusan preflight dibuat. */
    public function test_rollback_source_ews_blocks_concurrent_retirement_writer_before_preflight(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $target = RefStatusPegawai::query()->where('kode', 'PENSIUN')->firstOrFail();
        $source = $this->activeAlertFor(
            $employee,
            'PENSIUN',
            now('Asia/Makassar')->addDays(90)->toDateString(),
            90,
        );
        $paths = $this->racePaths('migration');
        $writerPaths = $this->racePaths('writer');
        $migration = $this->worker('EwsSourceRollbackMigrationWorker.php', $paths);
        $writer = $this->worker('EwsRetirementTransitionInsertWorker.php', [
            'employee_id' => $employee->id,
            'status_id' => $target->id,
            'source_ews_alert_id' => $source->id,
            'tanggal_efektif' => now('Asia/Makassar')->addDays(90)->toDateString(),
            ...$writerPaths,
        ]);
        $raceState = null;
        $barrier = [];
        $migrationOutcome = [];
        $writerOutcome = [];

        try {
            $migration->start();
            $this->assertTrue($this->waitForFile($paths['ready']), 'Worker rollback tidak mencapai barrier preflight.');
            $barrier = $this->readJson($paths['ready']);

            $writer->start();
            $this->assertTrue($this->waitForFile($writerPaths['ready']), 'Writer retirement tidak menulis PID.');
            $writerPid = (int) ($this->readJson($writerPaths['ready'])['pid'] ?? 0);
            $raceState = $this->waitForLockOrCompletion($writer, $writerPid);

            File::put($paths['release'], 'continue');
            $migration->wait();
            $writer->wait();
            $migrationOutcome = $this->readJson($paths['result']);
            $writerOutcome = $this->readJson($writerPaths['result']);
        } finally {
            File::put($paths['release'], 'cleanup');
            foreach ([$migration, $writer] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            if (! Schema::hasColumn('employee_status_transitions', 'source_ews_alert_id')) {
                DB::table('employee_status_transitions')
                    ->where('kind', EmployeeStatusTransition::KIND_EWS_RETIREMENT)
                    ->delete();
                $this->invokeMigration('up');
            }
        }

        $this->assertSame('blocked', $raceState, json_encode([
            'barrier' => $barrier,
            'migration' => $migrationOutcome,
            'writer' => $writerOutcome,
        ]));
        $this->assertSame('lock', $barrier['stage'] ?? null, json_encode($barrier));
        $this->assertTrue((bool) ($migrationOutcome['ok'] ?? false), json_encode($migrationOutcome));
        $this->assertFalse((bool) ($writerOutcome['ok'] ?? true), json_encode($writerOutcome));
        $this->assertDatabaseMissing('employee_status_transitions', [
            'kind' => EmployeeStatusTransition::KIND_EWS_RETIREMENT,
        ]);
        $this->assertTrue(Schema::hasColumn('employee_status_transitions', 'source_ews_alert_id'));
    }

    private function activeAlertFor(Employee $employee, string $type, string $targetDate, int $intervalDays): EwsAlert
    {
        return EwsAlert::query()->create([
            'employee_id' => $employee->id,
            'type' => $type,
            'target_date' => $targetDate,
            'interval_days' => $intervalDays,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
    }

    /** @return array{ready:string,release:string,result:string} */
    private function racePaths(string $name): array
    {
        return [
            'ready' => $this->raceDirectory.'/'.$name.'-ready.json',
            'release' => $this->raceDirectory.'/'.$name.'-release',
            'result' => $this->raceDirectory.'/'.$name.'-result.json',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function worker(string $fixture, array $payload): Process
    {
        return new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/'.$fixture),
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ], base_path(), timeout: 60);
    }

    private function waitForFile(string $path, int $timeoutMilliseconds = 30_000): bool
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

    private function waitForLockOrCompletion(Process $process, int $pid): string
    {
        $deadline = microtime(true) + 5;

        do {
            if (! $process->isRunning()) {
                return 'completed';
            }

            $activity = DB::selectOne(
                'SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?',
                [$pid],
            );
            if (($activity->wait_event_type ?? null) === 'Lock') {
                return 'blocked';
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return 'timeout';
    }

    private function waitForFileOrDatabaseLock(Process $process, int $pid, string $path): string
    {
        $deadline = microtime(true) + 5;

        do {
            clearstatcache(true, $path);
            if (File::exists($path)) {
                return 'notification_locked';
            }

            if (! $process->isRunning()) {
                return 'completed';
            }

            $activity = DB::selectOne(
                'SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?',
                [$pid],
            );
            if (($activity->wait_event_type ?? null) === 'Lock') {
                return 'blocked_on_alert';
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return 'timeout';
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function processDiagnostic(Process $process, string $resultPath): string
    {
        return json_encode([
            'result' => $this->readJson($resultPath),
            'stdout' => trim($process->getOutput()),
            'stderr' => trim($process->getErrorOutput()),
        ], JSON_THROW_ON_ERROR);
    }

    private function invokeMigration(string $method): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        (new ReflectionMethod($migration, $method))->invoke($migration);
    }
}
