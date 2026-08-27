<?php

namespace Tests\Feature;

use App\Actions\Cuti\StoreManualLeaveUsageAction;
use App\Models\Employee;
use App\Models\LeaveUsageDocument;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\StorageRecoveryTask;
use App\Models\User;
use App\Services\Cuti\LeaveUsageDocumentService;
use App\Services\StorageRecoveryService;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Mockery;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/** Regresi PostgreSQL agar dokumen pemakaian privat selalu memiliki manifest sebelum byte ditulis. */
class LeaveUsageDocumentCrashSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const OBSERVER_CONNECTION = 'pgsql_leave_usage_document_observer';

    /** @var list<string> */
    private array $committedRecoveryTaskIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Crash safety dokumen pemakaian cuti wajib diverifikasi pada PostgreSQL.');
        }

        config([
            'database.connections.'.self::OBSERVER_CONNECTION => DB::connection()->getConfig(),
        ]);
        DB::purge(self::OBSERVER_CONNECTION);

        // Callback ini terdaftar setelah rollback RefreshDatabase agar hanya intent committed
        // oleh koneksi independen yang dibersihkan setelah row lock transaksi test dilepas.
        $this->beforeApplicationDestroyed(function (): void {
            try {
                $taskIds = array_values(array_unique($this->committedRecoveryTaskIds));
                if ($taskIds !== []) {
                    DB::connection(self::OBSERVER_CONNECTION)
                        ->table('storage_recovery_tasks')
                        ->whereIn('id', $taskIds)
                        ->delete();
                }
            } finally {
                DB::disconnect(self::OBSERVER_CONNECTION);
            }
        });

        Storage::fake(LeaveUsageDocument::STORAGE_DISK);
        Queue::fake();
        Mail::fake();
        Notification::fake();
    }

    public function test_intent_cleanup_terlihat_committed_sebelum_dokumen_pemakaian_ditulis(): void
    {
        $employee = Employee::factory()->create();
        $realDisk = Storage::disk(LeaveUsageDocument::STORAGE_DISK);
        $observerSawCommittedIntent = false;
        $probedDisk = Mockery::mock(FilesystemAdapter::class);
        $this->expectation($probedDisk, 'putFileAs')->once()->andReturnUsing(
            function (string $directory, UploadedFile $file, string $storedName) use (
                $employee,
                $realDisk,
                &$observerSawCommittedIntent,
            ): string|false {
                $path = $directory.'/'.$storedName;
                $realPath = $file->getRealPath();
                if (! is_string($realPath)) {
                    throw new LogicException('Fixture dokumen tidak memiliki path lokal yang valid.');
                }
                $sha256 = hash_file('sha256', $realPath);
                if (! is_string($sha256)) {
                    throw new LogicException('Fixture dokumen tidak dapat dihitung SHA-256-nya.');
                }

                $observerSawCommittedIntent = DB::connection(self::OBSERVER_CONNECTION)
                    ->table('storage_recovery_tasks')
                    ->where('operation', StorageRecoveryTask::OPERATION_CREATION_TARGET)
                    ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                    ->where('category', StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT)
                    ->where('disk', LeaveUsageDocument::STORAGE_DISK)
                    ->where('path', $path)
                    ->where('owner_id', $employee->id)
                    ->where('sha256', $sha256)
                    ->exists();

                return $realDisk->putFileAs($directory, $file, $storedName);
            },
        );
        Storage::shouldReceive('disk')
            ->with(LeaveUsageDocument::STORAGE_DISK)
            ->andReturn($probedDisk);

        $stored = DB::transaction(
            fn (): array => app(LeaveUsageDocumentService::class)->store(
                $this->document('intent-before-write.pdf'),
                $employee->id,
            ),
        );
        $this->rememberCommittedRecoveryTask($stored['recovery_task_id']);

        $this->assertTrue(
            $observerSawCommittedIntent,
            'Manifest cleanup harus committed pada koneksi lain sebelum dokumen pemakaian ditulis.',
        );
    }

    public function test_retry_menghapus_dokumen_yatim_setelah_process_berhenti_seusai_write(): void
    {
        $employee = Employee::factory()->create();
        DB::beginTransaction();
        try {
            $stored = DB::transaction(
                fn (): array => app(LeaveUsageDocumentService::class)->store(
                    $this->document('orphan-after-write.pdf'),
                    $employee->id,
                ),
            );
        } finally {
            DB::rollBack();
        }
        $intent = $this->committedUsageIntent($stored);

        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_PREPARED,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT,
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'path' => $stored['path'],
            'owner_id' => $employee->id,
        ]);
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->assertExists($stored['path']);
        $this->assertDatabaseCount('leave_usage_documents', 0);

        $this->advancePastCreationGrace($intent);
        try {
            $this->assertRecoveryAttemptSucceeds($intent);
        } finally {
            Carbon::setTestNow();
        }

        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->assertMissing($stored['path']);
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'id' => $intent->id,
            'status' => StorageRecoveryTask::STATUS_COMPLETED,
            'attempts' => 1,
        ]);
    }

    public function test_retry_mengadopsi_dokumen_yang_metadata_nya_sudah_commit(): void
    {
        [$record, $employee, $admin] = $this->storeManualUsage(null);
        $stored = app(LeaveUsageDocumentService::class)->store(
            $this->document('metadata-committed.pdf'),
            $employee->id,
        );
        $intent = $this->committedUsageIntent($stored);
        DB::transaction(
            fn (): LeaveUsageDocument => app(LeaveUsageDocumentService::class)
                ->attachToUsage($record, $stored, $admin),
        );

        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_PREPARED,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT,
            'path' => $stored['path'],
        ]);
        $this->assertSame(StorageRecoveryTask::STATUS_PREPARED, $intent->status);

        $this->advancePastCreationGrace($intent);
        try {
            $this->assertRecoveryAttemptSucceeds($intent);
        } finally {
            Carbon::setTestNow();
        }

        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->assertExists($stored['path']);
        $this->assertDatabaseHas('leave_usage_documents', [
            'leave_usage_record_id' => $record->id,
            'path' => $stored['path'],
        ]);
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'id' => $intent->id,
            'status' => StorageRecoveryTask::STATUS_ADOPTED,
            'attempts' => 1,
        ]);
    }

    public function test_adopsi_eksplisit_memindahkan_intent_ke_manual_review_saat_file_target_hilang(): void
    {
        [$intent, $path, $employeeId] = $this->referencedPreparedUsageIntent(null);

        $this->attemptExplicitAdoption($intent, $employeeId, $path);

        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->assertMissing($path);
        $this->assertIntentRequiresManualReview($intent);
    }

    public function test_adopsi_eksplisit_memindahkan_intent_ke_manual_review_saat_file_target_rusak(): void
    {
        $corruptContents = "%PDF-1.4\n% isi berubah setelah intent dibuat\n%%EOF\n";
        [$intent, $path, $employeeId] = $this->referencedPreparedUsageIntent($corruptContents);

        $this->attemptExplicitAdoption($intent, $employeeId, $path);

        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->assertExists($path);
        $this->assertSame(
            $corruptContents,
            Storage::disk(LeaveUsageDocument::STORAGE_DISK)->get($path),
            'File yang gagal verifikasi integritas harus dipertahankan untuk pemeriksaan manual.',
        );
        $this->assertIntentRequiresManualReview($intent);
    }

    public function test_retry_memindahkan_intent_ke_manual_review_saat_metadata_merujuk_file_hilang(): void
    {
        [$intent, $path] = $this->referencedPreparedUsageIntent(null);

        $this->advancePastCreationGrace($intent);
        try {
            $attempted = app(StorageRecoveryService::class)->attempt($intent->id);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertFalse($attempted, $this->recoveryDiagnostic($intent));
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->assertMissing($path);
        $this->assertIntentRequiresManualReview($intent);
    }

    public function test_retry_memindahkan_intent_ke_manual_review_dan_mempertahankan_file_rusak(): void
    {
        $corruptContents = "%PDF-1.4\n% byte target tidak cocok dengan SHA intent\n%%EOF\n";
        [$intent, $path] = $this->referencedPreparedUsageIntent($corruptContents);

        $this->advancePastCreationGrace($intent);
        try {
            $attempted = app(StorageRecoveryService::class)->attempt($intent->id);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertFalse($attempted, $this->recoveryDiagnostic($intent));
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->assertExists($path);
        $this->assertSame(
            $corruptContents,
            Storage::disk(LeaveUsageDocument::STORAGE_DISK)->get($path),
            'Recovery tidak boleh menghapus file yang hash-nya gagal diverifikasi.',
        );
        $this->assertIntentRequiresManualReview($intent);
    }

    public function test_jalur_normal_mengadopsi_intent_setelah_metadata_dokumen_commit(): void
    {
        [$record, $employee] = $this->storeManualUsage($this->document('normal-adopted.pdf'));
        $document = $record->documents()->sole();

        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->assertExists($document->path);
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_ADOPTED,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT,
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'path' => $document->path,
            'owner_id' => $employee->id,
        ]);
        $this->assertCount(2, $record->externalApprovalSteps()->get());
        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertDatabaseCount('leave_request_steps', 0);
    }

    public function test_dokumen_tetap_opsional_tanpa_mengubah_snapshot_persetujuan_eksternal(): void
    {
        [$record] = $this->storeManualUsage(null);

        $this->assertDatabaseCount('leave_usage_documents', 0);
        $this->assertDatabaseMissing('storage_recovery_tasks', [
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT,
        ]);
        $this->assertCount(2, $record->externalApprovalSteps()->get());
        $this->assertDatabaseCount('leave_requests', 0);
        $this->assertDatabaseCount('leave_request_steps', 0);
    }

    #[DataProvider('invalidCreationTargetProvider')]
    public function test_creation_target_dengan_path_atau_sha_tidak_valid_fail_closed(string $invalidField): void
    {
        $employeeId = (string) Str::uuid();
        $contents = "%PDF-1.4\n% invalid intent\n%%EOF\n";
        $path = LeaveUsageDocument::PATH_PREFIX.'/'.$employeeId.'/'.Str::uuid().'.pdf';
        $sha256 = hash('sha256', $contents);
        if ($invalidField === 'path') {
            $path = LeaveUsageDocument::PATH_PREFIX.'/'.$employeeId.'/bukan-uuid.pdf';
        } else {
            $sha256 = 'sha-tidak-valid';
        }
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->put($path, $contents);
        $intent = $this->preparedUsageIntent($employeeId, $path, $sha256);

        $this->advancePastCreationGrace($intent);
        try {
            $this->assertFalse(app(StorageRecoveryService::class)->attempt($intent->id));
        } finally {
            Carbon::setTestNow();
        }

        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->assertExists($path);
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'id' => $intent->id,
            'status' => StorageRecoveryTask::STATUS_MANUAL_REVIEW,
            'attempts' => 1,
        ]);
    }

    public function test_command_recovery_menunggu_grace_lalu_menghapus_creation_target_yatim(): void
    {
        $employeeId = (string) Str::uuid();
        $path = LeaveUsageDocument::PATH_PREFIX.'/'.$employeeId.'/'.Str::uuid().'.pdf';
        $contents = "%PDF-1.4\n% orphan command recovery\n%%EOF\n";
        $intent = $this->preparedUsageIntent($employeeId, $path, hash('sha256', $contents));
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->put($path, $contents);

        $this->assertRecoveryCommandSucceeds($intent);
        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->assertExists($path);
        $this->assertSame(StorageRecoveryTask::STATUS_PREPARED, $intent->fresh()->status);
        $this->assertSame(0, $intent->fresh()->attempts);

        $this->advancePastCreationGrace($intent);
        try {
            $this->assertRecoveryCommandSucceeds($intent);
        } finally {
            Carbon::setTestNow();
        }

        Storage::disk(LeaveUsageDocument::STORAGE_DISK)->assertMissing($path);
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $intent->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function invalidCreationTargetProvider(): array
    {
        return [
            'path bukan UUID kanonis' => ['path'],
            'SHA-256 malformed' => ['sha256'],
        ];
    }

    /** @return array{LeaveUsageRecord, Employee, User} */
    private function storeManualUsage(?UploadedFile $document): array
    {
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $leaveType = RefJenisCuti::query()->create([
            'code' => 'usage_document_crash_'.Str::lower(Str::random(8)),
            'nama' => 'Cuti Dokumen Crash Safety',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $request = Request::create('/cuti/pemakaian-manual', 'POST');
        $request->setUserResolver(fn (): User => $admin);
        $record = app(StoreManualLeaveUsageAction::class)->execute(
            $employee->id,
            [
                'leave_type_id' => $leaveType->id,
                'leave_request_case_id' => null,
                'tanggal_mulai' => '2026-08-26',
                'tanggal_selesai' => '2026-08-26',
                'alasan' => 'Fakta eksternal untuk regresi crash safety dokumen.',
                'approval_document_number' => null,
                'approval_steps' => $this->approvalSteps(),
            ],
            $document,
            $admin,
            $request,
        );
        if ($document instanceof UploadedFile) {
            $documentPath = $record->documents()->sole()->path;
            $taskId = StorageRecoveryTask::query()
                ->where('operation', StorageRecoveryTask::OPERATION_CREATION_TARGET)
                ->where('category', StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT)
                ->where('path', $documentPath)
                ->value('id');
            if (! is_string($taskId)) {
                throw new LogicException('Manifest committed dokumen pemakaian tidak ditemukan untuk cleanup test.');
            }
            $this->rememberCommittedRecoveryTask($taskId);
        }

        return [$record, $employee, $admin];
    }

    /** @return list<array<string, mixed>> */
    private function approvalSteps(): array
    {
        return [
            [
                'step_type' => 'kepala_bagian',
                'approver_source' => 'external_official',
                'approver_employee_id' => null,
                'approver_name' => 'Kepala Bagian Eksternal',
                'approver_position' => 'Kepala Bagian',
                'approver_institution' => 'Instansi Eksternal',
                'acted_on' => '2026-08-24',
                'decision_note' => null,
            ],
            [
                'step_type' => 'pybmc',
                'approver_source' => 'external_official',
                'approver_employee_id' => null,
                'approver_name' => 'PYBMC Eksternal',
                'approver_position' => 'PYBMC',
                'approver_institution' => 'Instansi Eksternal',
                'acted_on' => '2026-08-25',
                'decision_note' => 'Disetujui di luar SIMPEG.',
            ],
        ];
    }

    /**
     * Membuat metadata append-only yang sengaja merujuk target PREPARED tanpa menjalankan adopsi normal.
     *
     * @return array{StorageRecoveryTask, string, string}
     */
    private function referencedPreparedUsageIntent(?string $actualContents): array
    {
        [$record, $employee, $admin] = $this->storeManualUsage(null);
        $expectedContents = "%PDF-1.4\n% isi yang dipin intent\n%%EOF\n";
        $storedName = Str::uuid().'.pdf';
        $path = LeaveUsageDocument::PATH_PREFIX.'/'.$employee->id.'/'.$storedName;
        $intent = $this->preparedUsageIntent($employee->id, $path, hash('sha256', $expectedContents));

        DB::transaction(
            fn (): LeaveUsageDocument => app(LeaveUsageDocumentService::class)->attachToUsage(
                $record,
                [
                    'original_name' => 'referenced-target.pdf',
                    'stored_name' => $storedName,
                    'path' => $path,
                    'disk' => LeaveUsageDocument::STORAGE_DISK,
                    'mime_type' => 'application/pdf',
                    'size_bytes' => strlen($expectedContents),
                    'recovery_task_id' => $intent->id,
                ],
                $admin,
            ),
        );

        if ($actualContents !== null
            && ! Storage::disk(LeaveUsageDocument::STORAGE_DISK)->put($path, $actualContents)) {
            throw new LogicException('Fixture target dokumen pemakaian gagal ditulis.');
        }

        return [$intent, $path, $employee->id];
    }

    private function preparedUsageIntent(string $employeeId, string $path, string $sha256): StorageRecoveryTask
    {
        return StorageRecoveryTask::query()->create([
            'idempotency_key' => hash('sha256', implode('|', [$employeeId, $path, $sha256])),
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_PREPARED,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT,
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'path' => $path,
            'owner_id' => $employeeId,
            'source_disk' => null,
            'source_path' => null,
            'sha256' => $sha256,
            'attempts' => 0,
            'last_error' => null,
            'last_attempted_at' => null,
            'completed_at' => null,
        ]);
    }

    /**
     * @param  array{path:string,recovery_task_id:string}  $stored
     */
    private function committedUsageIntent(array $stored): StorageRecoveryTask
    {
        $this->rememberCommittedRecoveryTask($stored['recovery_task_id']);

        return StorageRecoveryTask::query()
            ->whereKey($stored['recovery_task_id'])
            ->where('operation', StorageRecoveryTask::OPERATION_CREATION_TARGET)
            ->where('category', StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT)
            ->where('path', $stored['path'])
            ->sole();
    }

    private function rememberCommittedRecoveryTask(string $taskId): void
    {
        $this->committedRecoveryTaskIds[] = $taskId;
    }

    private function document(string $name): UploadedFile
    {
        return UploadedFile::fake()
            ->createWithContent($name, "%PDF-1.4\n% {$name}\n%%EOF\n")
            ->mimeType('application/pdf');
    }

    private function assertRecoveryAttemptSucceeds(StorageRecoveryTask $intent): void
    {
        $attempted = app(StorageRecoveryService::class)->attempt($intent->id);

        $this->assertTrue($attempted, $this->recoveryDiagnostic($intent));
    }

    /** Error boleh diteruskan ke caller, tetapi ledger durable tetap wajib berakhir di manual review. */
    private function attemptExplicitAdoption(
        StorageRecoveryTask $intent,
        string $employeeId,
        string $path,
    ): void {
        try {
            app(StorageRecoveryService::class)->markLeaveUsageDocumentCreationTargetAdopted(
                $intent->id,
                $employeeId,
                $path,
            );
        } catch (Throwable) {
            // Status durable diverifikasi terpisah agar test tidak mengunci pilihan kontrak exception caller.
        }
    }

    private function assertIntentRequiresManualReview(StorageRecoveryTask $intent): void
    {
        $diagnostic = DB::table('storage_recovery_tasks')
            ->where('id', $intent->id)
            ->first(['status', 'attempts', 'last_error']);

        $this->assertNotNull($diagnostic, 'Intent recovery tidak ditemukan setelah verifikasi target gagal.');
        $this->assertSame(
            StorageRecoveryTask::STATUS_MANUAL_REVIEW,
            $diagnostic->status,
            json_encode($diagnostic, JSON_THROW_ON_ERROR),
        );
    }

    private function recoveryDiagnostic(StorageRecoveryTask $intent): string
    {
        $diagnostic = DB::table('storage_recovery_tasks')
            ->where('id', $intent->id)
            ->first(['status', 'attempts', 'last_error']);

        return json_encode($diagnostic, JSON_THROW_ON_ERROR);
    }

    private function assertRecoveryCommandSucceeds(StorageRecoveryTask $intent): void
    {
        $exitCode = Artisan::call('storage:retry-recovery');
        $diagnostic = DB::table('storage_recovery_tasks')
            ->where('id', $intent->id)
            ->first(['status', 'attempts', 'last_error']);
        $message = json_encode([
            'task' => $diagnostic,
            'output' => Artisan::output(),
        ], JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $exitCode, $message);
    }

    /** Membekukan clock pada instant +120 detik tanpa mengubah epoch saat timezone aplikasi diterapkan. */
    private function advancePastCreationGrace(StorageRecoveryTask $intent): void
    {
        $createdAt = $intent->fresh()->created_at;
        if (! $createdAt instanceof Carbon) {
            throw new LogicException('Timestamp creation intent tidak dicast sebagai Carbon.');
        }

        Carbon::setTestNow(Carbon::createFromTimestamp(
            $createdAt->getTimestamp() + 120,
            (string) config('app.timezone'),
        ));
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
