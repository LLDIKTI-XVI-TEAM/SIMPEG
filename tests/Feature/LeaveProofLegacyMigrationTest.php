<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\StorageRecoveryTask;
use App\Models\User;
use App\Services\Cuti\LeaveProofDocumentStorageService;
use App\Services\StorageRecoveryService;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/** Regresi cutover bukti cuti legacy pada command deployment storage yang resmi. */
#[Group('serial')]
class LeaveProofLegacyMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake('public');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Database disposable perlu dikosongkan sebelum barrier rollback histori backfill cuti berjalan.
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs;
DROP TRIGGER IF EXISTS audit_logs_append_only_truncate ON audit_logs;
SQL);
            DB::table('audit_logs')->delete();
        }
        Str::createUuidsNormally();

        parent::tearDown();
    }

    public function test_dry_run_lalu_execute_memigrasikan_bukti_legacy_dan_memulihkan_download_berwenang(): void
    {
        [$leave, $proof, $pimpinan] = $this->createApprovedLeaveWithLegacyProof();
        $legacyPath = (string) $proof->document_path;
        $pdf = $this->validPdf('legacy berhasil');
        Storage::disk('local')->put($legacyPath, $pdf);

        $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.document.download', $leave))
            ->assertNotFound();

        $this->artisan('documents:migrate-to-private-storage')
            ->expectsOutputToContain('Mode dry-run')
            ->expectsOutputToContain('siap=1')
            ->assertSuccessful();

        $this->assertSame($legacyPath, $proof->fresh()->document_path);
        Storage::disk('local')->assertExists($legacyPath);
        $this->assertSame(0, StorageRecoveryTask::query()->count());

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('Mode execute')
            ->expectsOutputToContain('dipindahkan=1')
            ->assertSuccessful();

        $migrated = $proof->fresh();
        $targetPath = (string) $migrated->document_path;
        $this->assertMatchesRegularExpression(
            '#\Aleave-proofs/'.preg_quote($leave->id, '#').'/[0-9a-f-]{36}\.pdf\z#D',
            $targetPath,
        );
        $this->assertSame('application/pdf', $migrated->document_mime);
        Storage::disk('local')->assertMissing($legacyPath);
        Storage::disk('local')->assertExists($targetPath);
        $this->assertSame(hash('sha256', $pdf), hash('sha256', Storage::disk('local')->get($targetPath)));

        $this->assertDatabaseHas('audit_logs', [
            'user_name' => 'SIMPEG Database Upgrade',
            'event' => 'UPDATE',
            'auditable_type' => 'LeaveProof',
            'auditable_id' => $proof->id,
        ]);
        $auditPayload = DB::table('audit_logs')
            ->where('auditable_id', $proof->id)
            ->sole(['old_values', 'new_values']);
        $encodedAudit = json_encode($auditPayload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($legacyPath, $encodedAudit);
        $this->assertStringNotContainsString($targetPath, $encodedAudit);

        $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.document.download', $leave))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_execute_ulang_setelah_sukses_adalah_no_op_idempoten(): void
    {
        [, $proof] = $this->createApprovedLeaveWithLegacyProof();
        $legacyPath = (string) $proof->document_path;
        Storage::disk('local')->put($legacyPath, $this->validPdf('retry no-op'));

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])->assertSuccessful();
        $firstPath = (string) $proof->fresh()->document_path;
        $firstFiles = Storage::disk('local')->allFiles('leave-proofs');
        $firstTasks = StorageRecoveryTask::query()->count();
        $firstAudits = DB::table('audit_logs')
            ->where('auditable_id', $proof->id)
            ->count();

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('sudah_privat=1')
            ->assertSuccessful();

        $this->assertSame($firstPath, $proof->fresh()->document_path);
        $this->assertSame($firstFiles, Storage::disk('local')->allFiles('leave-proofs'));
        $this->assertSame($firstTasks, StorageRecoveryTask::query()->count());
        $this->assertSame(
            $firstAudits,
            DB::table('audit_logs')
                ->where('auditable_id', $proof->id)
                ->count(),
        );
    }

    public function test_missing_source_dilaporkan_fail_closed_tanpa_mengubah_metadata(): void
    {
        [, $proof] = $this->createApprovedLeaveWithLegacyProof();
        $legacyPath = (string) $proof->document_path;

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('hilang: bukti cuti legacy')
            ->expectsOutputToContain('hilang=1')
            ->assertFailed();

        $this->assertSame($legacyPath, $proof->fresh()->document_path);
        $this->assertSame(0, StorageRecoveryTask::query()->count());
    }

    public function test_exception_exists_path_kanonis_diredaksi_dan_dihitung_sebagai_konflik(): void
    {
        [$leave, $proof] = $this->createApprovedLeaveWithLegacyProof();
        $canonicalPath = 'leave-proofs/'.$leave->id.'/00000000-0000-4000-8000-000000000898.pdf';
        $sentinel = 'private/rahasia/path-kanonis-jangan-bocor.pdf';
        $local = Storage::disk('local');
        $local->put($canonicalPath, $this->validPdf('kanonis tetap utuh'));
        $proof->forceFill(['document_path' => $canonicalPath])->save();
        $beforeProof = $proof->fresh()->getAttributes();
        $beforeTaskCount = StorageRecoveryTask::query()->count();
        $beforeAuditCount = DB::table('audit_logs')->count();
        $failingLocal = $this->filesystemMock();
        $this->expectation($failingLocal, 'exists')->zeroOrMoreTimes()->andReturnUsing(
            function (string $path) use ($local, $canonicalPath, $sentinel): bool {
                if ($path === $canonicalPath) {
                    throw new \RuntimeException('Adapter gagal memeriksa '.$sentinel);
                }

                return $local->exists($path);
            },
        );
        $this->mockStorageDisks($failingLocal);

        [$exitCode, $output, $exception] = $this->callDocumentMigrationCapturingFailure();
        $observableFailure = $output."\n".($exception?->getMessage() ?? '');

        $this->assertStringNotContainsString($sentinel, $observableFailure);
        $this->assertNull($exception, 'Exception adapter harus ditangani tanpa keluar dari command.');
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString(
            'konflik: keberadaan bukti cuti kanonis gagal diperiksa secara fail-closed.',
            $output,
        );
        $this->assertStringContainsString('konflik=1', $output);
        $this->assertSame($beforeProof, $proof->fresh()->getAttributes());
        $this->assertSame($beforeTaskCount, StorageRecoveryTask::query()->count());
        $this->assertSame($beforeAuditCount, DB::table('audit_logs')->count());
        $this->assertSame($this->validPdf('kanonis tetap utuh'), $local->get($canonicalPath));
    }

    public function test_exception_exists_path_legacy_diredaksi_dan_dihitung_sebagai_tidak_valid(): void
    {
        [, $proof] = $this->createApprovedLeaveWithLegacyProof();
        $legacyPath = (string) $proof->document_path;
        $sentinel = 'private/rahasia/path-legacy-jangan-bocor.pdf';
        $legacyPdf = $this->validPdf('legacy tetap utuh');
        $local = Storage::disk('local');
        $local->put($legacyPath, $legacyPdf);
        $beforeProof = $proof->fresh()->getAttributes();
        $beforeTaskCount = StorageRecoveryTask::query()->count();
        $beforeAuditCount = DB::table('audit_logs')->count();
        $failingLocal = $this->filesystemMock();
        $this->expectation($failingLocal, 'exists')->zeroOrMoreTimes()->andReturnUsing(
            function (string $path) use ($local, $legacyPath, $sentinel): bool {
                if ($path === $legacyPath) {
                    throw new \RuntimeException('Adapter gagal memeriksa '.$sentinel);
                }

                return $local->exists($path);
            },
        );
        $this->mockStorageDisks($failingLocal);

        [$exitCode, $output, $exception] = $this->callDocumentMigrationCapturingFailure();
        $observableFailure = $output."\n".($exception?->getMessage() ?? '');

        $this->assertStringNotContainsString($sentinel, $observableFailure);
        $this->assertNull($exception, 'Exception adapter harus ditangani tanpa keluar dari command.');
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString(
            'tidak_valid: keberadaan bukti cuti legacy gagal diperiksa secara fail-closed.',
            $output,
        );
        $this->assertStringContainsString('tidak_valid=1', $output);
        $this->assertSame($beforeProof, $proof->fresh()->getAttributes());
        $this->assertSame($beforeTaskCount, StorageRecoveryTask::query()->count());
        $this->assertSame($beforeAuditCount, DB::table('audit_logs')->count());
        $this->assertSame($legacyPdf, $local->get($legacyPath));
    }

    public function test_target_existing_dengan_hash_berbeda_dilaporkan_konflik_dan_tidak_diadopsi(): void
    {
        [$leave, $proof] = $this->createApprovedLeaveWithLegacyProof();
        $legacyPath = (string) $proof->document_path;
        $sourcePdf = $this->validPdf('source konflik');
        $targetUuid = '00000000-0000-4000-8000-000000000861';
        $targetPath = 'leave-proofs/'.$leave->id.'/'.$targetUuid.'.pdf';
        Storage::disk('local')->put($legacyPath, $sourcePdf);
        Storage::disk('local')->put($targetPath, $this->validPdf('target berbeda'));
        $this->fakeUuidSequence($targetUuid);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('konflik: migrasi bukti cuti')
            ->expectsOutputToContain('konflik=1')
            ->assertFailed();

        $this->assertSame($legacyPath, $proof->fresh()->document_path);
        Storage::disk('local')->assertExists($legacyPath);
        $this->assertStringContainsString('target berbeda', Storage::disk('local')->get($targetPath));
        $intent = StorageRecoveryTask::query()
            ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
            ->where('path', $targetPath)
            ->sole();
        $this->assertSame(StorageRecoveryTask::STATUS_PREPARED, $intent->status);

        $this->artisan('storage:retry-recovery')->assertFailed();
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $intent->fresh()->status);
        Storage::disk('local')->assertExists($targetPath);
    }

    public function test_file_non_pdf_dilaporkan_tidak_valid_dan_tetap_dipertahankan(): void
    {
        [, $proof] = $this->createApprovedLeaveWithLegacyProof();
        $legacyPath = (string) $proof->document_path;
        $invalid = "MZ\x90\x00binary executable";
        Storage::disk('local')->put($legacyPath, $invalid);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('tidak_valid: bukti cuti legacy')
            ->expectsOutputToContain('tidak_valid=1')
            ->assertFailed();

        $this->assertSame($legacyPath, $proof->fresh()->document_path);
        $this->assertSame($invalid, Storage::disk('local')->get($legacyPath));
        $this->assertSame(0, StorageRecoveryTask::query()->count());
    }

    public function test_hanya_exact_legacy_path_yang_diizinkan_dan_traversal_tidak_menyentuh_sentinel(): void
    {
        [$leave, $proof] = $this->createApprovedLeaveWithLegacyProof();
        $sentinelPath = 'sentinel/di-luar-scope.pdf';
        $sentinel = $this->validPdf('jangan disentuh');
        Storage::disk('local')->put($sentinelPath, $sentinel);
        $traversal = 'leave-proofs/'.$leave->id.'/../../'.$sentinelPath;
        $proof->forceFill(['document_path' => $traversal])->save();

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('tidak_valid: path bukti cuti legacy')
            ->expectsOutputToContain('tidak_valid=1')
            ->assertFailed();

        $this->assertSame($traversal, $proof->fresh()->document_path);
        $this->assertSame($sentinel, Storage::disk('local')->get($sentinelPath));
        $this->assertSame(0, StorageRecoveryTask::query()->count());

        $nearMiss = 'leave-proofs/'.$leave->id.'.PDF';
        $proof->forceFill(['document_path' => $nearMiss])->save();
        Storage::disk('local')->put($nearMiss, $this->validPdf('ekstensi bukan exact'));

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('tidak_valid: path bukti cuti legacy')
            ->assertFailed();

        $this->assertSame($nearMiss, $proof->fresh()->document_path);
        Storage::disk('local')->assertExists($nearMiss);
        $this->assertSame($sentinel, Storage::disk('local')->get($sentinelPath));
    }

    public function test_validator_runtime_requires_adopted_manifest_in_addition_to_canonical_path(): void
    {
        $leaveRequestId = '00000000-0000-4000-8000-000000000891';
        $validUuid = 'a0000000-b000-4c00-8d00-e00000000892';
        $validPath = 'leave-proofs/'.$leaveRequestId.'/'.$validUuid.'.pdf';
        $invalidPaths = [
            'leave-proofs/'.$leaveRequestId.'/'.$validUuid.'.PDF',
            'leave-proofs/'.$leaveRequestId.'/'.strtoupper($validUuid).'.pdf',
            'leave-proofs/'.$leaveRequestId.'/00000000-0000-0000-0000-000000000000.pdf',
            'leave-proofs/'.$leaveRequestId.'/00000000-0000-4000-7000-000000000893.pdf',
        ];
        $runtime = $this->app->make(LeaveProofDocumentStorageService::class);
        $recovery = $this->app->make(StorageRecoveryService::class);
        Storage::disk('local')->put($validPath, $this->validPdf('predicate valid'));

        $this->assertFalse(
            $runtime->isValidExistingDocument($leaveRequestId, $validPath, 'application/pdf'),
            'Path kanonis tanpa manifest ADOPTED belum cukup untuk menjadi artifact resmi.',
        );
        $this->assertTrue($recovery->isCanonicalLeaveProofPath($validPath, $leaveRequestId));

        foreach ($invalidPaths as $invalidPath) {
            Storage::disk('local')->put($invalidPath, $this->validPdf('predicate invalid'));

            $this->assertFalse(
                $runtime->isValidExistingDocument($leaveRequestId, $invalidPath, 'application/pdf'),
                'Validator runtime harus menolak near-miss '.$invalidPath,
            );
            $this->assertFalse(
                $recovery->isCanonicalLeaveProofPath($invalidPath, $leaveRequestId),
                'Validator recovery harus menolak near-miss yang sama dengan runtime.',
            );
        }
    }

    public function test_dry_run_melaporkan_konflik_intent_prepared_tanpa_mutasi_state(): void
    {
        [$leave, $proof] = $this->createApprovedLeaveWithLegacyProof();
        $legacyPath = (string) $proof->document_path;
        $sourcePdf = $this->validPdf('dry-run source');
        $targetPath = 'leave-proofs/'.$leave->id.'/00000000-0000-4000-8000-000000000894.pdf';
        Storage::disk('local')->put($legacyPath, $sourcePdf);
        Storage::disk('local')->put($targetPath, $this->validPdf('dry-run target konflik'));
        $intent = $this->app->make(StorageRecoveryService::class)->prepareLeaveProofMigrationTarget(
            $targetPath,
            $leave->id,
            $legacyPath,
            hash('sha256', $sourcePdf),
        );
        $beforeProof = $proof->fresh()->getAttributes();
        $beforeIntent = $intent->fresh()->getAttributes();
        $beforeFiles = $this->localFileHashes();
        $beforeTaskCount = StorageRecoveryTask::query()->count();
        $beforeAuditCount = DB::table('audit_logs')->count();

        $this->artisan('documents:migrate-to-private-storage')
            ->expectsOutputToContain('konflik: migrasi bukti cuti')
            ->expectsOutputToContain('konflik=1')
            ->assertFailed();

        $this->assertSame($beforeProof, $proof->fresh()->getAttributes());
        $this->assertSame($beforeIntent, $intent->fresh()->getAttributes());
        $this->assertSame($beforeFiles, $this->localFileHashes());
        $this->assertSame($beforeTaskCount, StorageRecoveryTask::query()->count());
        $this->assertSame($beforeAuditCount, DB::table('audit_logs')->count());
    }

    public function test_dry_run_target_prepared_oversized_ditolak_sebelum_stream_target_dibaca(): void
    {
        [$leave, $proof] = $this->createApprovedLeaveWithLegacyProof();
        $legacyPath = (string) $proof->document_path;
        $sourcePdf = $this->validPdf('source bounded target');
        $targetPath = 'leave-proofs/'.$leave->id.'/00000000-0000-4000-8000-000000000897.pdf';
        $local = Storage::disk('local');
        $local->put($legacyPath, $sourcePdf);
        $local->put($targetPath, $sourcePdf);
        $intent = $this->app->make(StorageRecoveryService::class)->prepareLeaveProofMigrationTarget(
            $targetPath,
            $leave->id,
            $legacyPath,
            hash('sha256', $sourcePdf),
        );
        $beforeProof = $proof->fresh()->getAttributes();
        $beforeIntent = $intent->fresh()->getAttributes();
        $beforeTaskCount = StorageRecoveryTask::query()->count();
        $beforeAuditCount = DB::table('audit_logs')->count();
        $targetReadCount = 0;
        $oversizedTargetLocal = $this->filesystemMock();
        $this->expectation($oversizedTargetLocal, 'exists')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $path): bool => $local->exists($path),
        );
        $this->expectation($oversizedTargetLocal, 'size')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $path): int => $path === $targetPath
                ? (10 * 1024 * 1024) + 1
                : $local->size($path),
        );
        $this->expectation($oversizedTargetLocal, 'readStream')->zeroOrMoreTimes()->andReturnUsing(
            function (string $path) use ($local, $targetPath, &$targetReadCount) {
                if ($path === $targetPath) {
                    $targetReadCount++;
                }

                return $local->readStream($path);
            },
        );
        $this->mockStorageDisks($oversizedTargetLocal);

        $exitCode = Artisan::call('documents:migrate-to-private-storage');
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('konflik: migrasi bukti cuti', $output);
        $this->assertSame(0, $targetReadCount, 'Target oversized tidak boleh dibuka sebagai stream.');
        $this->assertSame($beforeProof, $proof->fresh()->getAttributes());
        $this->assertSame($beforeIntent, $intent->fresh()->getAttributes());
        $this->assertSame($beforeTaskCount, StorageRecoveryTask::query()->count());
        $this->assertSame($beforeAuditCount, DB::table('audit_logs')->count());
        $this->assertSame($sourcePdf, $local->get($legacyPath));
        $this->assertSame($sourcePdf, $local->get($targetPath));
    }

    public function test_source_berubah_setelah_prevalidasi_membatalkan_cutover_tanpa_audit(): void
    {
        [$leave, $proof] = $this->createApprovedLeaveWithLegacyProof();
        $legacyPath = (string) $proof->document_path;
        $sourcePdf = $this->validPdf('source sebelum lock');
        $replacementPdf = $this->validPdf('source diganti saat cutover');
        $targetUuid = '00000000-0000-4000-8000-000000000895';
        $targetPath = 'leave-proofs/'.$leave->id.'/'.$targetUuid.'.pdf';
        $local = Storage::disk('local');
        $local->put($legacyPath, $sourcePdf);
        $mutatingLocal = $this->delegatingFilesystemMock($local);
        $this->expectation($mutatingLocal, 'writeStream')->once()->with($targetPath, \Mockery::any())->andReturnUsing(
            function (string $path, mixed $stream) use ($local, $legacyPath, $replacementPdf): bool {
                $written = $local->writeStream($path, $stream);
                $local->put($legacyPath, $replacementPdf);

                return $written;
            },
        );
        $this->mockStorageDisks($mutatingLocal);
        $this->fakeUuidSequence($targetUuid);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('konflik: migrasi bukti cuti')
            ->assertFailed();

        $this->assertSame($legacyPath, $proof->fresh()->document_path);
        $this->assertSame($replacementPdf, $local->get($legacyPath));
        $this->assertTrue($local->exists($targetPath));
        $this->assertSame(
            StorageRecoveryTask::STATUS_PREPARED,
            StorageRecoveryTask::query()->where('path', $targetPath)->sole()->status,
        );
        $this->assertSame(0, DB::table('audit_logs')->where('auditable_id', $proof->id)->count());
    }

    public function test_crash_setelah_metadata_commit_dapat_retry_cleanup_source_secara_idempoten(): void
    {
        [$proof, $legacyPath, $targetPath, $local, $sourceTask] = $this->stagePostCommitCleanupCrash();

        $this->artisan('storage:retry-recovery')->assertSuccessful();

        $this->assertSame($targetPath, $proof->fresh()->document_path);
        $this->assertFalse($local->exists($legacyPath));
        $this->assertTrue($local->exists($targetPath));
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $sourceTask->fresh()->status);

        $this->artisan('storage:retry-recovery')->assertSuccessful();
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $sourceTask->fresh()->status);
        $this->assertFalse($local->exists($legacyPath));
    }

    public function test_retry_cleanup_source_yang_hashnya_berubah_masuk_manual_review(): void
    {
        [$proof, $legacyPath, $targetPath, $local, $sourceTask] = $this->stagePostCommitCleanupCrash();
        $local->put($legacyPath, $this->validPdf('source berubah setelah commit'));

        $this->artisan('storage:retry-recovery')->assertFailed();

        $this->assertSame($targetPath, $proof->fresh()->document_path);
        $this->assertTrue($local->exists($legacyPath));
        $this->assertTrue($local->exists($targetPath));
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $sourceTask->fresh()->status);
    }

    public function test_retry_cleanup_dengan_counterpart_kanonis_hilang_masuk_manual_review(): void
    {
        [$proof, $legacyPath, $targetPath, $local, $sourceTask] = $this->stagePostCommitCleanupCrash();
        $local->delete($targetPath);

        $this->artisan('storage:retry-recovery')->assertFailed();

        $this->assertSame($targetPath, $proof->fresh()->document_path);
        $this->assertTrue($local->exists($legacyPath));
        $this->assertFalse($local->exists($targetPath));
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $sourceTask->fresh()->status);
    }

    public function test_retry_cleanup_dengan_counterpart_kanonis_berubah_masuk_manual_review(): void
    {
        [$proof, $legacyPath, $targetPath, $local, $sourceTask] = $this->stagePostCommitCleanupCrash();
        $local->put($targetPath, $this->validPdf('target berubah setelah commit'));

        $this->artisan('storage:retry-recovery')->assertFailed();

        $this->assertSame($targetPath, $proof->fresh()->document_path);
        $this->assertTrue($local->exists($legacyPath));
        $this->assertTrue($local->exists($targetPath));
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $sourceTask->fresh()->status);
    }

    #[DataProvider('invalidCounterpartAfterSourceDeleteProvider')]
    public function test_retry_setelah_source_terhapus_memvalidasi_counterpart_sebelum_completed(
        string $counterpartState,
    ): void {
        [$proof, $legacyPath, $targetPath, $local, $sourceTask] = $this->stagePostCommitCleanupCrash();
        $local->delete($legacyPath);
        if ($counterpartState === 'hilang') {
            $local->delete($targetPath);
        } else {
            $local->put($targetPath, $this->validPdf('target korup setelah source terhapus'));
        }

        $this->artisan('storage:retry-recovery')->assertFailed();

        $this->assertSame($targetPath, $proof->fresh()->document_path);
        $this->assertFalse($local->exists($legacyPath));
        $this->assertSame(StorageRecoveryTask::STATUS_MANUAL_REVIEW, $sourceTask->fresh()->status);
        if ($counterpartState === 'hilang') {
            $this->assertFalse($local->exists($targetPath));
        } else {
            $this->assertStringContainsString('target korup', $local->get($targetPath));
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidCounterpartAfterSourceDeleteProvider(): array
    {
        return [
            'counterpart hilang' => ['hilang'],
            'counterpart berubah' => ['berubah'],
        ];
    }

    public function test_retry_setelah_source_terhapus_completed_bila_counterpart_tetap_valid(): void
    {
        [$proof, $legacyPath, $targetPath, $local, $sourceTask] = $this->stagePostCommitCleanupCrash();
        $local->delete($legacyPath);

        $this->artisan('storage:retry-recovery')->assertSuccessful();

        $this->assertSame($targetPath, $proof->fresh()->document_path);
        $this->assertFalse($local->exists($legacyPath));
        $this->assertTrue($local->exists($targetPath));
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $sourceTask->fresh()->status);
    }

    public function test_deskripsi_command_menjelaskan_cutover_bukti_cuti_privat_ke_privat(): void
    {
        $command = Artisan::all()['documents:migrate-to-private-storage'];
        $executeDescription = $command->getDefinition()->getOption('execute')->getDescription();

        $this->assertStringContainsString('bukti cuti legacy privat', strtolower($command->getDescription()));
        $this->assertStringContainsString('source legacy privat', strtolower($executeDescription));
    }

    public function test_crash_setelah_target_write_mempertahankan_source_dan_retry_membersihkan_target_yatim(): void
    {
        [$leave, $proof] = $this->createApprovedLeaveWithLegacyProof();
        $legacyPath = (string) $proof->document_path;
        $pdf = $this->validPdf('crash window');
        $targetUuid = '00000000-0000-4000-8000-000000000871';
        $targetPath = 'leave-proofs/'.$leave->id.'/'.$targetUuid.'.pdf';
        $local = Storage::disk('local');
        $public = Storage::disk('public');
        $employeeDocuments = Storage::disk(Document::STORAGE_DISK);
        $local->put($legacyPath, $pdf);
        $failingLocal = $this->delegatingFilesystemMock($local);
        $this->expectation($failingLocal, 'writeStream')->once()->with($targetPath, \Mockery::any())->andReturnUsing(
            function (string $path, mixed $stream) use ($local): bool {
                $local->writeStream($path, $stream);

                throw new \RuntimeException('Fault privat rahasia pada '.$path);
            },
        );
        Storage::shouldReceive('disk')->with('public')->andReturn($public);
        Storage::shouldReceive('disk')->with(Document::STORAGE_DISK)->andReturn($employeeDocuments);
        Storage::shouldReceive('disk')->with('local')->andReturn($failingLocal);
        $this->fakeUuidSequence($targetUuid);

        $exitCode = Artisan::call('documents:migrate-to-private-storage', ['--execute' => true]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('konflik: migrasi bukti cuti', $output);
        $this->assertStringNotContainsString('Fault privat rahasia', $output);
        $this->assertStringNotContainsString($targetPath, $output);

        $this->assertSame($legacyPath, $proof->fresh()->document_path);
        $this->assertTrue($local->exists($legacyPath));
        $this->assertTrue($local->exists($targetPath));
        $intent = StorageRecoveryTask::query()
            ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
            ->where('path', $targetPath)
            ->sole();
        $this->assertSame(StorageRecoveryTask::STATUS_PREPARED, $intent->status);

        $this->artisan('storage:retry-recovery')->assertSuccessful();

        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $intent->fresh()->status);
        $this->assertTrue($local->exists($legacyPath));
        $this->assertFalse($local->exists($targetPath));
        $this->assertSame($legacyPath, $proof->fresh()->document_path);
    }

    /**
     * Membentuk crash setelah commit metadata dengan delete source pertama gagal.
     *
     * @return array{LeaveProof, string, string, Filesystem, StorageRecoveryTask}
     */
    private function stagePostCommitCleanupCrash(): array
    {
        [$leave, $proof] = $this->createApprovedLeaveWithLegacyProof();
        $legacyPath = (string) $proof->document_path;
        $sourcePdf = $this->validPdf('post-commit cleanup');
        $targetUuid = '00000000-0000-4000-8000-000000000896';
        $targetPath = 'leave-proofs/'.$leave->id.'/'.$targetUuid.'.pdf';
        $local = Storage::disk('local');
        $local->put($legacyPath, $sourcePdf);
        $failingDeleteLocal = $this->delegatingFilesystemMock($local, failFirstDeletePath: $legacyPath);
        $this->expectation($failingDeleteLocal, 'writeStream')->once()->with($targetPath, \Mockery::any())->andReturnUsing(
            fn (string $path, mixed $stream): bool => $local->writeStream($path, $stream),
        );
        $this->mockStorageDisks($failingDeleteLocal);
        $this->fakeUuidSequence($targetUuid);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('cleanup source bukti cuti legacy tertunda')
            ->assertFailed();

        $proof->refresh();
        $this->assertSame($targetPath, $proof->document_path);
        $this->assertTrue($local->exists($legacyPath));
        $this->assertTrue($local->exists($targetPath));
        $sourceTask = StorageRecoveryTask::query()
            ->where('operation', StorageRecoveryTask::OPERATION_DELETE)
            ->where('category', StorageRecoveryService::CATEGORY_LEAVE_PROOF_LEGACY_SOURCE)
            ->sole();
        $this->assertSame(StorageRecoveryTask::STATUS_PENDING, $sourceTask->status);

        return [$proof, $legacyPath, $targetPath, $local, $sourceTask];
    }

    /** @return array{LeaveRequest, LeaveProof, User} */
    private function createApprovedLeaveWithLegacyProof(): array
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pemohon Bukti Legacy']);
        $leaveType = RefJenisCuti::create([
            'code' => 'sakit_proof_'.Str::lower(Str::random(8)),
            'nama' => 'Cuti Sakit Bukti Legacy',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-03',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Migrasi bukti cuti legacy.',
            'status' => 'disetujui',
        ]);
        $pimpinan = User::factory()->pimpinan()->create();
        $proof = LeaveProof::create([
            'leave_request_id' => $leave->id,
            'token' => hash('sha256', 'legacy-proof-'.$leave->id),
            'document_path' => 'leave-proofs/'.$leave->id.'.pdf',
            'document_mime' => 'application/pdf',
            'generated_by' => $pimpinan->id,
            'generated_at' => now(),
            'metadata' => ['status' => 'Disetujui'],
        ]);

        return [$leave, $proof, $pimpinan];
    }

    /** Membuat mock filesystem dengan kontrak adapter yang sama seperti storage production. */
    private function filesystemMock(): Filesystem&MockInterface
    {
        $mock = \Mockery::mock(Filesystem::class);
        if (! $mock instanceof Filesystem) {
            throw new LogicException('Mock filesystem tidak memenuhi kontrak yang diminta.');
        }

        return $mock;
    }

    /** Membuat mock yang mendelegasikan operasi storage selain fault terkontrol ke fake disk. */
    private function delegatingFilesystemMock(
        Filesystem $disk,
        ?string $failFirstDeletePath = null,
    ): Filesystem&MockInterface {
        $mock = $this->filesystemMock();
        $this->expectation($mock, 'exists')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $path): bool => $disk->exists($path),
        );
        $this->expectation($mock, 'size')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $path): int => $disk->size($path),
        );
        $this->expectation($mock, 'readStream')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $path) => $disk->readStream($path),
        );
        $failedDelete = false;
        $this->expectation($mock, 'delete')->zeroOrMoreTimes()->andReturnUsing(
            function (string $path) use ($disk, $failFirstDeletePath, &$failedDelete): bool {
                if (! $failedDelete && $failFirstDeletePath !== null && $path === $failFirstDeletePath) {
                    $failedDelete = true;

                    return false;
                }

                return $disk->delete($path);
            },
        );

        return $mock;
    }

    private function mockStorageDisks(Filesystem $local): void
    {
        $public = Storage::disk('public');
        $employeeDocuments = Storage::disk(Document::STORAGE_DISK);
        Storage::shouldReceive('disk')->with('public')->andReturn($public);
        Storage::shouldReceive('disk')->with(Document::STORAGE_DISK)->andReturn($employeeDocuments);
        Storage::shouldReceive('disk')->with('local')->andReturn($local);
    }

    /** @return array{?int, string, ?\Throwable} */
    private function callDocumentMigrationCapturingFailure(): array
    {
        $exitCode = null;
        $exception = null;

        try {
            $exitCode = Artisan::call('documents:migrate-to-private-storage', ['--execute' => true]);
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        return [$exitCode, Artisan::output(), $exception];
    }

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
            Uuid::fromString('00000000-0000-4000-8000-000000000872'),
            Uuid::fromString('00000000-0000-4000-8000-000000000873'),
            Uuid::fromString('00000000-0000-4000-8000-000000000874'),
        ]);
    }

    private function validPdf(string $marker): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Title ({$marker}) >>\nendobj\n%%EOF\n";
    }

    /** @return array<string, string> */
    private function localFileHashes(): array
    {
        $hashes = [];
        foreach (Storage::disk('local')->allFiles() as $path) {
            $hashes[$path] = hash('sha256', Storage::disk('local')->get($path));
        }
        ksort($hashes);

        return $hashes;
    }
}
