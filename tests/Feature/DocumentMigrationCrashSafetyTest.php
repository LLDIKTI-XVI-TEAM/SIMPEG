<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\StorageRecoveryTask;
use App\Services\StorageRecoveryService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/** Regresi PostgreSQL tanpa outer transaction untuk membuktikan batas commit manifest migrasi. */
#[Group('serial')]
class DocumentMigrationCrashSafetyTest extends TestCase
{
    use DatabaseMigrations;

    private const OBSERVER_CONNECTION = 'pgsql_migration_observer';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.'.self::OBSERVER_CONNECTION => config('database.connections.pgsql'),
        ]);
        DB::purge(self::OBSERVER_CONNECTION);
    }

    protected function tearDown(): void
    {
        Str::createUuidsNormally();
        DB::disconnect(self::OBSERVER_CONNECTION);

        parent::tearDown();
    }

    public function test_target_cleanup_intent_terlihat_committed_dari_koneksi_lain_sebelum_target_write(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $public = Storage::disk('public');
        $employeeDocuments = Storage::disk(Document::STORAGE_DISK);
        $leaveDocuments = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $sourcePath = 'cuti/observer-precommit.pdf';
        $pdf = $this->validPdf('observer commit');
        $public->put($sourcePath, $pdf);
        $employee = Employee::factory()->create();
        $leave = $this->createLeaveWithAttachment($employee, $sourcePath);
        $targetUuid = '00000000-0000-4000-8000-000000000964';
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/'.$targetUuid.'.pdf';
        $observerSawCommittedIntent = false;
        $failingPrivate = $this->filesystemMock();
        $this->expectation($failingPrivate, 'exists')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $path): bool => $leaveDocuments->exists($path),
        );
        $this->expectation($failingPrivate, 'readStream')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $path) => $leaveDocuments->readStream($path),
        );
        $this->expectation($failingPrivate, 'writeStream')->once()->andReturnUsing(
            function (string $path, mixed $stream) use ($leaveDocuments, $targetPath, $pdf, &$observerSawCommittedIntent): bool {
                $observerSawCommittedIntent = DB::connection(self::OBSERVER_CONNECTION)
                    ->table('storage_recovery_tasks')
                    ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                    ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                    ->where('category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT)
                    ->where('disk', LeaveRequest::ATTACHMENT_STORAGE_DISK)
                    ->where('path', $targetPath)
                    ->where('sha256', hash('sha256', $pdf))
                    ->exists();
                $leaveDocuments->writeStream($path, $stream);

                throw new \RuntimeException('Fault terkontrol setelah target write.');
            },
        );
        $this->expectation($failingPrivate, 'delete')->never();
        Storage::shouldReceive('disk')->with('public')->andReturn($public);
        Storage::shouldReceive('disk')->with(Document::STORAGE_DISK)->andReturn($employeeDocuments);
        Storage::shouldReceive('disk')->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)->andReturn($failingPrivate);
        $this->fakeUuidSequence($targetUuid);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])->assertFailed();

        $this->assertTrue($observerSawCommittedIntent);
        $this->assertSame($sourcePath, $leave->fresh()->lampiran_path);
        $this->assertTrue($public->exists($sourcePath));
        $this->assertTrue($leaveDocuments->exists($targetPath));
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'path' => $targetPath,
            'status' => StorageRecoveryTask::STATUS_PREPARED,
            'sha256' => hash('sha256', $pdf),
        ]);
    }

    public function test_main_migration_menolak_intent_yang_diselesaikan_retry_di_gap_sebelum_lock_dan_tidak_menulis_target(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $public = Storage::disk('public');
        $employeeDocuments = Storage::disk(Document::STORAGE_DISK);
        $leaveDocuments = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $sourcePath = 'cuti/race-intent-completed.pdf';
        $pdf = $this->validPdf('race completed');
        $public->put($sourcePath, $pdf);
        $employee = Employee::factory()->create();
        $leave = $this->createLeaveWithAttachment($employee, $sourcePath);
        $targetUuid = '00000000-0000-4000-8000-000000000969';
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/'.$targetUuid.'.pdf';
        $raceInjected = false;
        Event::listen(TransactionCommitted::class, function () use ($sourcePath, &$raceInjected): void {
            if ($raceInjected) {
                return;
            }

            $intent = DB::connection(self::OBSERVER_CONNECTION)
                ->table('storage_recovery_tasks')
                ->where('source_disk', 'public')
                ->where('source_path', $sourcePath)
                ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                ->first();

            if ($intent === null) {
                return;
            }

            DB::connection(self::OBSERVER_CONNECTION)
                ->table('storage_recovery_tasks')
                ->where('id', $intent->id)
                ->update([
                    'status' => StorageRecoveryTask::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            $raceInjected = true;
        });
        $private = $this->filesystemMock();
        $this->expectation($private, 'exists')->never();
        $this->expectation($private, 'writeStream')->never();
        $this->expectation($private, 'delete')->never();
        Storage::shouldReceive('disk')->with('public')->andReturn($public);
        Storage::shouldReceive('disk')->with(Document::STORAGE_DISK)->andReturn($employeeDocuments);
        Storage::shouldReceive('disk')->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)->andReturn($private);
        $this->fakeUuidSequence($targetUuid);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])->assertFailed();

        $this->assertTrue($raceInjected);
        $this->assertSame($sourcePath, $leave->fresh()->lampiran_path);
        $this->assertTrue($public->exists($sourcePath));
        $this->assertFalse($leaveDocuments->exists($targetPath));
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'path' => $targetPath,
            'status' => StorageRecoveryTask::STATUS_COMPLETED,
        ]);
    }

    public function test_rerun_canonical_memulihkan_intent_completed_lalu_mengadopsi_target_dan_menghapus_public(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $public = Storage::disk('public');
        $employeeDocuments = Storage::disk(Document::STORAGE_DISK);
        $leaveDocuments = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $employee = Employee::factory()->create();
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/00000000-0000-4000-8000-000000000981.pdf';
        $pdf = $this->validPdf('canonical rerun');
        $public->put($path, $pdf);
        $leave = $this->createLeaveWithAttachment($employee, $path);
        $raceInjected = false;
        Event::listen(TransactionCommitted::class, function () use ($path, &$raceInjected): void {
            if ($raceInjected) {
                return;
            }

            $intent = DB::connection(self::OBSERVER_CONNECTION)
                ->table('storage_recovery_tasks')
                ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                ->where('path', $path)
                ->where('source_path', $path)
                ->first();
            if ($intent === null) {
                return;
            }

            DB::connection(self::OBSERVER_CONNECTION)
                ->table('storage_recovery_tasks')
                ->where('id', $intent->id)
                ->update([
                    'status' => StorageRecoveryTask::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            $raceInjected = true;
        });
        $writes = 0;
        $private = $this->filesystemMock();
        $this->expectation($private, 'exists')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $targetPath): bool => $leaveDocuments->exists($targetPath),
        );
        $this->expectation($private, 'size')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $targetPath): int => $leaveDocuments->size($targetPath),
        );
        $this->expectation($private, 'readStream')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $targetPath) => $leaveDocuments->readStream($targetPath),
        );
        $this->expectation($private, 'writeStream')->once()->andReturnUsing(
            function (string $targetPath, mixed $stream) use ($leaveDocuments, &$writes): bool {
                $writes++;

                return $leaveDocuments->writeStream($targetPath, $stream);
            },
        );
        Storage::shouldReceive('disk')->with('public')->andReturn($public);
        Storage::shouldReceive('disk')->with(Document::STORAGE_DISK)->andReturn($employeeDocuments);
        Storage::shouldReceive('disk')->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)->andReturn($private);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])->assertFailed();

        $this->assertTrue($raceInjected);
        $this->assertSame(0, $writes);
        $this->assertSame($path, $leave->fresh()->lampiran_path);
        $this->assertTrue($public->exists($path));
        $this->assertFalse($leaveDocuments->exists($path));
        $intent = StorageRecoveryTask::query()
            ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
            ->where('path', $path)
            ->sole();
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $intent->status);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])->assertSuccessful();

        $this->assertSame(1, $writes);
        $this->assertSame($path, $leave->fresh()->lampiran_path);
        $this->assertSame(hash('sha256', $pdf), hash('sha256', $leaveDocuments->get($path)));
        $this->assertFalse($public->exists($path));
        $this->assertSame(StorageRecoveryTask::STATUS_ADOPTED, $intent->fresh()->status);
        $sourceTask = StorageRecoveryTask::query()
            ->where('operation', StorageRecoveryTask::OPERATION_DELETE)
            ->where('category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY)
            ->where('path', $path)
            ->sole();
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $sourceTask->status);
    }

    private function createLeaveWithAttachment(Employee $employee, string $path): LeaveRequest
    {
        $leaveType = RefJenisCuti::create([
            'code' => 'sakit_crash_safety_'.Str::lower(Str::random(8)),
            'nama' => 'Cuti Sakit Crash Safety',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);

        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-03',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Migrasi lampiran cuti crash-safe.',
            'lampiran_path' => $path,
            'status' => 'menunggu_approval',
        ]);
    }

    /** Membuat mock filesystem dengan kontrak adapter dan Mockery yang eksplisit. */
    private function filesystemMock(): Filesystem&MockInterface
    {
        $mock = \Mockery::mock(Filesystem::class);

        if (! $mock instanceof Filesystem) {
            throw new LogicException('Mock filesystem tidak memenuhi kontrak yang diminta.');
        }

        return $mock;
    }

    /** Mengubah hasil shouldReceive menjadi ekspektasi konkret yang dapat dikonfigurasi. */
    private function expectation(MockInterface $mock, string $method): Expectation|CompositeExpectation
    {
        $expectation = $mock->shouldReceive($method);

        if (! $expectation instanceof Expectation && ! $expectation instanceof CompositeExpectation) {
            throw new LogicException('Mockery tidak mengembalikan ekspektasi metode.');
        }

        return $expectation;
    }

    private function fakeUuidSequence(string $targetUuid): void
    {
        Str::createUuidsUsingSequence([
            Uuid::fromString($targetUuid),
            Uuid::fromString('00000000-0000-4000-8000-000000000970'),
            Uuid::fromString('00000000-0000-4000-8000-000000000971'),
            Uuid::fromString('00000000-0000-4000-8000-000000000972'),
        ]);
    }

    private function validPdf(string $marker): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Title ({$marker}) >>\nendobj\n%%EOF\n";
    }
}
