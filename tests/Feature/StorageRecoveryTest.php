<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageDocument;
use App\Models\RefJenisCuti;
use App\Models\StorageRecoveryTask;
use App\Services\Cuti\LeaveUsageDocumentService;
use App\Services\EmployeeFileStorageService;
use App\Services\StorageRecoveryService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StorageRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_storage_dijadwalkan_bounded_dengan_proteksi_overlap(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'storage:retry-recovery'));

        $this->assertNotNull($event);
        $this->assertStringContainsString('--limit=100', $event->command);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        $this->assertSame(config('app.timezone'), $event->timezone);
    }

    public function test_manifest_recovery_storage_menyimpan_operasi_dan_status_retry_secara_durable(): void
    {
        $this->assertTrue(Schema::hasColumns('storage_recovery_tasks', [
            'id',
            'idempotency_key',
            'operation',
            'status',
            'category',
            'disk',
            'path',
            'owner_id',
            'source_disk',
            'source_path',
            'sha256',
            'attempts',
            'last_error',
            'last_attempted_at',
            'completed_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_delete_false_menyisakan_task_durable_dan_command_retry_menyelesaikannya_secara_idempoten(): void
    {
        $ownerId = (string) Str::uuid();
        $path = 'cuti/lampiran/'.$ownerId.'/00000000-0000-4000-8000-000000000931.pdf';
        $deleteAttempts = 0;
        $disk = $this->filesystemMock();
        $this->expectation($disk, 'exists')->times(3)->with($path)->andReturn(true, true, false);
        $this->expectation($disk, 'delete')->twice()->with($path)->andReturnUsing(function () use (&$deleteAttempts): bool {
            $deleteAttempts++;

            return $deleteAttempts > 1;
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $recovery = app(StorageRecoveryService::class);

        $task = $recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            'local',
            $path,
            $ownerId,
        );
        $duplicate = $recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            'local',
            $path,
            $ownerId,
        );

        $this->assertSame($task->id, $duplicate->id);
        $this->assertDatabaseCount('storage_recovery_tasks', 1);
        $this->assertFalse($recovery->attempt($task->id));
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'id' => $task->id,
            'status' => StorageRecoveryTask::STATUS_PENDING,
            'attempts' => 1,
        ]);

        $this->artisan('storage:retry-recovery')->assertSuccessful();
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'id' => $task->id,
            'status' => StorageRecoveryTask::STATUS_COMPLETED,
            'attempts' => 2,
        ]);
    }

    public function test_delete_exception_tetap_menyisakan_task_pending_dengan_error_untuk_retry(): void
    {
        $ownerId = (string) Str::uuid();
        $path = 'leave-proofs/'.$ownerId.'/00000000-0000-4000-8000-000000000932.pdf';
        $disk = $this->filesystemMock();
        $this->expectation($disk, 'exists')->once()->with($path)->andReturnTrue();
        $this->expectation($disk, 'delete')->once()->with($path)->andThrow(new RuntimeException('storage offline'));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $recovery = app(StorageRecoveryService::class);
        $task = $recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_PROOF,
            'local',
            $path,
            $ownerId,
        );

        $this->assertFalse($recovery->attempt($task->id));

        $task->refresh();
        $this->assertSame(StorageRecoveryTask::STATUS_PENDING, $task->status);
        $this->assertSame(1, $task->attempts);
        $this->assertStringContainsString('storage offline', (string) $task->last_error);
    }

    public function test_command_retry_merotasi_task_yang_ditunda_agar_task_baru_tidak_kelaparan(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $employee = Employee::factory()->create();
        $leaveType = RefJenisCuti::query()->create(['nama' => 'Cuti Recovery Fairness']);
        $oldPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/00000000-0000-4000-8000-000000000941.pdf';
        $newPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/00000000-0000-4000-8000-000000000942.pdf';
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($oldPath, 'masih direferensikan');
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($newPath, 'aman dihapus');
        LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-03',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Menahan task lama agar tetap pending.',
            'status' => 'disetujui',
            'lampiran_path' => $oldPath,
        ]);

        $recovery = app(StorageRecoveryService::class);
        $oldTask = $recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $oldPath,
            $employee->id,
        );
        $newTask = $recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $newPath,
            $employee->id,
        );
        StorageRecoveryTask::query()->whereKey($oldTask->id)->update(['created_at' => now()->subMinutes(2)]);
        StorageRecoveryTask::query()->whereKey($newTask->id)->update(['created_at' => now()->subMinute()]);

        $this->artisan('storage:retry-recovery', ['--limit' => 1])->assertFailed();
        $this->assertSame(1, $oldTask->fresh()->attempts);
        $this->assertSame(0, $newTask->fresh()->attempts);

        $this->artisan('storage:retry-recovery', ['--limit' => 1])->assertFailed();

        $this->assertSame(1, $oldTask->fresh()->attempts);
        $this->assertSame(StorageRecoveryTask::STATUS_PENDING, $oldTask->fresh()->status);
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $newTask->fresh()->status);
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertExists($oldPath);
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertMissing($newPath);
    }

    public function test_schedule_delete_membuka_kembali_task_completed_bila_path_yang_sama_muncul_lagi(): void
    {
        $ownerId = (string) Str::uuid();
        $path = 'cuti/lampiran/'.$ownerId.'/00000000-0000-4000-8000-000000000937.pdf';
        $disk = $this->filesystemMock();
        $this->expectation($disk, 'exists')->times(4)->with($path)->andReturn(true, false, true, false);
        $this->expectation($disk, 'delete')->twice()->with($path)->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $recovery = app(StorageRecoveryService::class);
        $first = $recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            'local',
            $path,
            $ownerId,
        );
        $this->assertTrue($recovery->attempt($first->id));

        $reopened = $recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            'local',
            $path,
            $ownerId,
        );

        $this->assertSame($first->id, $reopened->id);
        $this->assertSame(StorageRecoveryTask::STATUS_PENDING, $reopened->status);
        $this->assertNull($reopened->completed_at);
        $this->assertTrue($recovery->attempt($reopened->id));
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'id' => $first->id,
            'status' => StorageRecoveryTask::STATUS_COMPLETED,
            'attempts' => 2,
        ]);
    }

    public function test_cleanup_lampiran_cuti_delete_false_diteruskan_ke_manifest_durable(): void
    {
        $ownerId = (string) Str::uuid();
        $path = 'cuti/lampiran/'.$ownerId.'/00000000-0000-4000-8000-000000000934.pdf';
        $disk = $this->filesystemMock();
        $this->expectation($disk, 'exists')->zeroOrMoreTimes()->with($path)->andReturnTrue();
        $this->expectation($disk, 'delete')->once()->with($path)->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        app(EmployeeFileStorageService::class)->deleteLeaveAttachment($path, $ownerId);

        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_DELETE,
            'status' => StorageRecoveryTask::STATUS_PENDING,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            'disk' => 'local',
            'path' => $path,
            'owner_id' => $ownerId,
            'attempts' => 1,
        ]);
    }

    public function test_cleanup_dokumen_pemakaian_delete_false_diteruskan_ke_manifest_durable(): void
    {
        $ownerId = (string) Str::uuid();
        $path = LeaveUsageDocument::PATH_PREFIX.'/'.$ownerId.'/00000000-0000-4000-8000-000000000935.pdf';
        $disk = $this->filesystemMock();
        $this->expectation($disk, 'exists')->zeroOrMoreTimes()->with($path)->andReturnTrue();
        $this->expectation($disk, 'delete')->once()->with($path)->andReturnFalse();
        Storage::shouldReceive('disk')->with(LeaveUsageDocument::STORAGE_DISK)->andReturn($disk);

        app(LeaveUsageDocumentService::class)->deleteNewFile([
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'path' => $path,
        ]);

        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_DELETE,
            'status' => StorageRecoveryTask::STATUS_PENDING,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT,
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'path' => $path,
            'owner_id' => $ownerId,
            'attempts' => 1,
        ]);
    }

    public function test_prepared_migration_target_dihapus_oleh_retry_bila_belum_diadopsi_database(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $ownerId = (string) Str::uuid();
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$ownerId.'/00000000-0000-4000-8000-000000000973.pdf';
        $sourcePath = 'cuti/prepared-target.pdf';
        $contents = "%PDF-1.4\n%%EOF\n";
        $sha256 = hash('sha256', $contents);
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($targetPath, $contents);
        Storage::disk('public')->put($sourcePath, $contents);

        $task = app(StorageRecoveryService::class)->prepareMigrationTarget(
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $targetPath,
            $ownerId,
            'public',
            $sourcePath,
            $sha256,
        );

        $this->assertSame(StorageRecoveryTask::OPERATION_MIGRATION_TARGET, $task->operation);
        $this->assertSame(StorageRecoveryTask::STATUS_PREPARED, $task->status);
        $this->artisan('storage:retry-recovery')->assertSuccessful();
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertMissing($targetPath);
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $task->fresh()->status);

        $reopened = app(StorageRecoveryService::class)->prepareMigrationTarget(
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $targetPath,
            $ownerId,
            'public',
            $sourcePath,
            $sha256,
        );
        $this->assertSame($task->id, $reopened->id);
        $this->assertSame(StorageRecoveryTask::STATUS_PREPARED, $reopened->status);
    }

    public function test_adopted_migration_target_tidak_dihapus_oleh_retry(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $ownerId = (string) Str::uuid();
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$ownerId.'/00000000-0000-4000-8000-000000000974.pdf';
        $contents = "%PDF-1.4\n%%EOF\n";
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($targetPath, $contents);
        $recovery = app(StorageRecoveryService::class);
        $task = $recovery->prepareMigrationTarget(
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $targetPath,
            $ownerId,
            'public',
            'cuti/adopted-target.pdf',
            hash('sha256', $contents),
        );

        $recovery->markMigrationTargetAdopted($task);

        $this->artisan('storage:retry-recovery')->assertSuccessful();
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertExists($targetPath);
        $this->assertSame(StorageRecoveryTask::STATUS_ADOPTED, $task->fresh()->status);
    }

    public function test_prepared_target_yang_sudah_direferensikan_db_masuk_manual_review_dan_tidak_dihapus(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $employee = Employee::factory()->create();
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/00000000-0000-4000-8000-000000000980.pdf';
        $contents = "%PDF-1.4\n%%EOF\n";
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($path, $contents);
        $task = app(StorageRecoveryService::class)->prepareMigrationTarget(
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $path,
            $employee->id,
            'public',
            'cuti/prepared-direferensikan.pdf',
            hash('sha256', $contents),
        );
        $leaveType = RefJenisCuti::create([
            'code' => 'prepared_recovery',
            'nama' => 'Prepared Recovery',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-03',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'State prepared yang tidak mungkin.',
            'lampiran_path' => $path,
            'status' => 'menunggu_approval',
        ]);

        $this->assertFalse(app(StorageRecoveryService::class)->attempt($task->id));
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertExists($path);
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $task->fresh()->status);
    }

    public function test_source_delete_masuk_manual_review_bila_salah_satu_target_adopted_hilang(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $sourcePath = 'cuti/target-hilang.pdf';
        $contents = "%PDF-1.4\n%%EOF\n";
        $sha256 = hash('sha256', $contents);
        Storage::disk('public')->put($sourcePath, $contents);
        $recovery = app(StorageRecoveryService::class);
        $owners = [(string) Str::uuid(), (string) Str::uuid()];

        foreach ($owners as $index => $ownerId) {
            $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$ownerId.'/00000000-0000-4000-8000-00000000097'.($index + 5).'.pdf';
            $task = $recovery->prepareMigrationTarget(
                LeaveRequest::ATTACHMENT_STORAGE_DISK,
                $targetPath,
                $ownerId,
                'public',
                $sourcePath,
                $sha256,
            );
            $recovery->markMigrationTargetAdopted($task);

            if ($index === 0) {
                Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($targetPath, $contents);
            }
        }

        $sourceTask = $recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            'public',
            $sourcePath,
            null,
            'public',
            $sourcePath,
            $sha256,
        );

        $this->assertFalse($recovery->attempt($sourceTask->id));
        Storage::disk('public')->assertExists($sourcePath);
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $sourceTask->fresh()->status);
    }

    public function test_canonical_public_copy_tidak_dihapus_bila_counterpart_privat_berbeda_hash(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $ownerId = (string) Str::uuid();
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$ownerId.'/00000000-0000-4000-8000-000000000978.pdf';
        $publicContents = "%PDF-1.4\n% public\n%%EOF\n";
        Storage::disk('public')->put($path, $publicContents);
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($path, "%PDF-1.4\n% private berbeda\n%%EOF\n");
        $recovery = app(StorageRecoveryService::class);
        $task = $recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY,
            'public',
            $path,
            $ownerId,
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $path,
            hash('sha256', $publicContents),
        );

        $this->assertFalse($recovery->attempt($task->id));
        Storage::disk('public')->assertExists($path);
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $task->fresh()->status);
    }

    public function test_canonical_public_copy_wajib_memiliki_hash_yang_dipin(): void
    {
        $ownerId = (string) Str::uuid();
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$ownerId.'/00000000-0000-4000-8000-000000000979.pdf';

        $this->expectException(RuntimeException::class);
        app(StorageRecoveryService::class)->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY,
            'public',
            $path,
            $ownerId,
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $path,
        );
    }

    #[DataProvider('invalidSourceStateProvider')]
    public function test_prepared_target_lampiran_dipertahankan_bila_source_hilang_atau_berubah(string $sourceState): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $ownerId = (string) Str::uuid();
        $sourcePath = 'cuti/prepared-source-'.$sourceState.'.pdf';
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$ownerId.'/00000000-0000-4000-8000-000000000a11.pdf';
        $expectedContents = "%PDF-1.4\n% source expected\n%%EOF\n";
        $target = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $source = Storage::disk('public');
        $target->put($targetPath, $expectedContents);
        if ($sourceState === 'berubah') {
            $source->put($sourcePath, "%PDF-1.4\n% source changed\n%%EOF\n");
        }
        $task = app(StorageRecoveryService::class)->prepareMigrationTarget(
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $targetPath,
            $ownerId,
            'public',
            $sourcePath,
            hash('sha256', $expectedContents),
        );

        $this->assertFalse(app(StorageRecoveryService::class)->attempt($task->id));

        $this->assertSame($expectedContents, $target->get($targetPath));
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $task->fresh()->status);
    }

    #[DataProvider('invalidSourceStateProvider')]
    public function test_prepared_target_bukti_cuti_dipertahankan_bila_source_hilang_atau_berubah(string $sourceState): void
    {
        Storage::fake('local');
        $leaveRequestId = (string) Str::uuid();
        $sourcePath = 'leave-proofs/'.$leaveRequestId.'.pdf';
        $targetPath = 'leave-proofs/'.$leaveRequestId.'/00000000-0000-4000-8000-000000000a12.pdf';
        $expectedContents = "%PDF-1.4\n% proof source expected\n%%EOF\n";
        $local = Storage::disk('local');
        $local->put($targetPath, $expectedContents);
        if ($sourceState === 'berubah') {
            $local->put($sourcePath, "%PDF-1.4\n% proof source changed\n%%EOF\n");
        }
        $task = app(StorageRecoveryService::class)->prepareLeaveProofMigrationTarget(
            $targetPath,
            $leaveRequestId,
            $sourcePath,
            hash('sha256', $expectedContents),
        );

        $this->assertFalse(app(StorageRecoveryService::class)->attempt($task->id));

        $this->assertSame($expectedContents, $local->get($targetPath));
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $task->fresh()->status);
    }

    #[DataProvider('invalidSourceStateProvider')]
    public function test_prepared_target_lampiran_yang_sudah_hilang_tetap_memerlukan_source_valid(string $sourceState): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $ownerId = (string) Str::uuid();
        $sourcePath = 'cuti/prepared-target-absent-'.$sourceState.'.pdf';
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$ownerId.'/00000000-0000-4000-8000-000000000a21.pdf';
        $expectedContents = "%PDF-1.4\n% absent target expected source\n%%EOF\n";
        if ($sourceState === 'berubah') {
            Storage::disk('public')->put($sourcePath, "%PDF-1.4\n% absent target changed source\n%%EOF\n");
        }
        $task = app(StorageRecoveryService::class)->prepareMigrationTarget(
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $targetPath,
            $ownerId,
            'public',
            $sourcePath,
            hash('sha256', $expectedContents),
        );

        $this->assertFalse(app(StorageRecoveryService::class)->attempt($task->id));

        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertMissing($targetPath);
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $task->fresh()->status);
    }

    #[DataProvider('invalidSourceStateProvider')]
    public function test_prepared_target_bukti_cuti_yang_sudah_hilang_tetap_memerlukan_source_valid(string $sourceState): void
    {
        Storage::fake('local');
        $leaveRequestId = (string) Str::uuid();
        $sourcePath = 'leave-proofs/'.$leaveRequestId.'.pdf';
        $targetPath = 'leave-proofs/'.$leaveRequestId.'/00000000-0000-4000-8000-000000000a22.pdf';
        $expectedContents = "%PDF-1.4\n% absent proof expected source\n%%EOF\n";
        if ($sourceState === 'berubah') {
            Storage::disk('local')->put($sourcePath, "%PDF-1.4\n% absent proof changed source\n%%EOF\n");
        }
        $task = app(StorageRecoveryService::class)->prepareLeaveProofMigrationTarget(
            $targetPath,
            $leaveRequestId,
            $sourcePath,
            hash('sha256', $expectedContents),
        );

        $this->assertFalse(app(StorageRecoveryService::class)->attempt($task->id));

        Storage::disk('local')->assertMissing($targetPath);
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $task->fresh()->status);
    }

    public function test_prepared_target_yang_sudah_hilang_completed_bila_source_masih_valid(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $ownerId = (string) Str::uuid();
        $sourcePath = 'cuti/prepared-target-absent-valid.pdf';
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$ownerId.'/00000000-0000-4000-8000-000000000a23.pdf';
        $contents = "%PDF-1.4\n% absent target valid source\n%%EOF\n";
        Storage::disk('public')->put($sourcePath, $contents);
        $task = app(StorageRecoveryService::class)->prepareMigrationTarget(
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $targetPath,
            $ownerId,
            'public',
            $sourcePath,
            hash('sha256', $contents),
        );

        $this->assertTrue(app(StorageRecoveryService::class)->attempt($task->id));

        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertMissing($targetPath);
        Storage::disk('public')->assertExists($sourcePath);
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $task->fresh()->status);
    }

    public function test_prepared_target_bukti_yang_sudah_hilang_completed_bila_source_masih_valid(): void
    {
        Storage::fake('local');
        $leaveRequestId = (string) Str::uuid();
        $sourcePath = 'leave-proofs/'.$leaveRequestId.'.pdf';
        $targetPath = 'leave-proofs/'.$leaveRequestId.'/00000000-0000-4000-8000-000000000a24.pdf';
        $contents = "%PDF-1.4\n% absent proof valid source\n%%EOF\n";
        Storage::disk('local')->put($sourcePath, $contents);
        $task = app(StorageRecoveryService::class)->prepareLeaveProofMigrationTarget(
            $targetPath,
            $leaveRequestId,
            $sourcePath,
            hash('sha256', $contents),
        );

        $this->assertTrue(app(StorageRecoveryService::class)->attempt($task->id));

        Storage::disk('local')->assertMissing($targetPath);
        Storage::disk('local')->assertExists($sourcePath);
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $task->fresh()->status);
    }

    public function test_delete_generik_target_hilang_tetap_completed_tanpa_source_migrasi(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $ownerId = (string) Str::uuid();
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$ownerId.'/00000000-0000-4000-8000-000000000a25.pdf';
        $task = app(StorageRecoveryService::class)->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $path,
            $ownerId,
        );

        $this->assertTrue(app(StorageRecoveryService::class)->attempt($task->id));

        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $task->fresh()->status);
    }

    #[DataProvider('invalidSourceStateProvider')]
    public function test_public_copy_source_yang_sudah_hilang_memerlukan_counterpart_privat_valid(string $counterpartState): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $ownerId = (string) Str::uuid();
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$ownerId.'/00000000-0000-4000-8000-000000000a13.pdf';
        $expectedContents = "%PDF-1.4\n% public copy expected\n%%EOF\n";
        if ($counterpartState === 'berubah') {
            Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)
                ->put($path, "%PDF-1.4\n% private counterpart changed\n%%EOF\n");
        }
        $task = app(StorageRecoveryService::class)->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY,
            'public',
            $path,
            $ownerId,
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $path,
            hash('sha256', $expectedContents),
        );

        $this->assertFalse(app(StorageRecoveryService::class)->attempt($task->id));

        Storage::disk('public')->assertMissing($path);
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $task->fresh()->status);
    }

    public function test_public_copy_source_yang_sudah_hilang_completed_bila_counterpart_privat_valid(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $ownerId = (string) Str::uuid();
        $path = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$ownerId.'/00000000-0000-4000-8000-000000000a14.pdf';
        $contents = "%PDF-1.4\n% retained private counterpart\n%%EOF\n";
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($path, $contents);
        $task = app(StorageRecoveryService::class)->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY,
            'public',
            $path,
            $ownerId,
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $path,
            hash('sha256', $contents),
        );

        $this->assertTrue(app(StorageRecoveryService::class)->attempt($task->id));

        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $task->fresh()->status);
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertExists($path);
    }

    /** @return array<string, array{string}> */
    public static function invalidSourceStateProvider(): array
    {
        return [
            'hilang' => ['hilang'],
            'berubah' => ['berubah'],
        ];
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
}
