<?php

namespace Tests\Feature;

use App\Actions\Employees\ExecuteImportBatchAction;
use App\Actions\Employees\QueueImportBatchAction;
use App\Actions\Employees\UploadImportBatchAction;
use App\Actions\Employees\ValidateImportBatchAction;
use App\Jobs\ImportEmployeeBatchJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmployeeImportExecutionRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    /**
     * NIP yang muncul setelah validasi harus menjadi satu outcome skip pada seluruh hasil import.
     */
    public function test_execution_time_duplicate_nip_is_counted_as_skipped_everywhere(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batch = app(UploadImportBatchAction::class)->execute(
            $this->csvFile($this->validCsv()),
            'utama',
            $user,
        );
        $batchId = $batch['batch_id'];

        $validation = app(ValidateImportBatchAction::class)->execute($batchId, null, $user);
        $this->assertSame(1, $validation['valid_count']);
        $this->assertSame(0, $validation['skip_count']);

        $employeeCountBeforeRace = Employee::count();
        Employee::factory()->create(['nip' => '198001012006041001']);
        $this->persistQueuedBatch($batchId, $user);

        $result = app(ExecuteImportBatchAction::class)->execute($batchId, $user);

        $this->assertDatabaseCount('employees', $employeeCountBeforeRace + 1);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['inserted_count']);
        $this->assertSame(1, $result['skipped_count']);

        $persistedBatch = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame($result['inserted_count'], $persistedBatch->inserted_count);
        $this->assertSame($result['skipped_count'], $persistedBatch->skipped_count);
        $this->assertSame([
            [
                'row' => 2,
                'nama' => 'Budi Santoso',
                'kategori' => 'dilewati',
                'errors' => [
                    'NIP' => ['NIP sudah terdaftar saat proses import dijalankan.'],
                ],
            ],
        ], $persistedBatch->row_issues);

        $cachedBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
        $this->assertSame(0, $cachedBatch['result']['inserted']);
        $this->assertSame(1, $cachedBatch['result']['skipped']);
        $this->assertSame($persistedBatch->row_issues, $cachedBatch['row_issues']);

        $audit = AuditLog::query()
            ->where('event', 'IMPORT')
            ->where('auditable_type', 'Employee')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame(0, $audit->new_values['total_inserted']);
        $this->assertSame(1, $audit->new_values['total_skipped']);

        $this->actingAs($user);
        $report = $this->get("/pegawai/import/{$batchId}/laporan");
        $report->assertOk();
        $csv = $report->streamedContent();
        $this->assertStringContainsString('"Berhasil ditambahkan",0', $csv);
        $this->assertStringContainsString('"Dilewati (NIP terdaftar)",1', $csv);
    }

    /** Pelanggaran unique selain NIP harus dilempar ulang dan baru terminal setelah retry habis. */
    public function test_execution_does_not_classify_another_unique_constraint_as_duplicate_nip(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batch = app(UploadImportBatchAction::class)->execute(
            $this->csvFile($this->validCsv('Budi employees_nip_unique Santoso')),
            'utama',
            $user,
        );
        $batchId = $batch['batch_id'];

        $validation = app(ValidateImportBatchAction::class)->execute($batchId, null, $user);
        $this->assertSame(1, $validation['valid_count']);

        DB::statement(
            'CREATE UNIQUE INDEX employee_import_race_email_unique '
            .'ON employees (LOWER(email_pribadi)) WHERE email_pribadi IS NOT NULL',
        );
        Employee::factory()->create([
            'nip' => '199001012015041003',
            'email_pribadi' => 'budi@example.com',
        ]);
        $this->persistQueuedBatch($batchId, $user);

        $caughtException = null;
        try {
            app(ExecuteImportBatchAction::class)->execute($batchId, $user);
            $this->fail('Unique violation email seharusnya dilempar ulang.');
        } catch (QueryException $exception) {
            $caughtException = $exception;
            $this->assertStringContainsString('employee_import_race_email_unique', $exception->getMessage());
        }

        $persistedBatch = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('queued', $persistedBatch->status);
        $this->assertSame(0, $persistedBatch->inserted_count);
        $this->assertSame(0, $persistedBatch->skipped_count);

        (new ImportEmployeeBatchJob($batchId, $user->id))->failed($caughtException);

        $this->assertSame('failed', ImportBatch::query()->findOrFail($batchId)->status);
    }

    /** Retry worker melanjutkan baris yang belum commit tanpa mengubah hasil attempt pertama menjadi skip. */
    public function test_transient_failure_after_committed_row_resumes_from_durable_checkpoint(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
        ]);
        app()->forgetInstance('queue.worker');

        $user = User::factory()->adminKepegawaian()->create();
        $batch = app(UploadImportBatchAction::class)->execute(
            $this->csvFile($this->twoRowCsv()),
            'utama',
            $user,
        );
        $batchId = $batch['batch_id'];
        app(ValidateImportBatchAction::class)->execute($batchId, null, $user);

        $temporaryConflict = Employee::factory()->create([
            'nip' => '199001012015041003',
            'email_pribadi' => 'siti@example.com',
        ]);
        DB::statement(
            'CREATE UNIQUE INDEX employee_import_retry_email_unique '
            .'ON employees (LOWER(email_pribadi)) WHERE email_pribadi IS NOT NULL',
        );

        app(QueueImportBatchAction::class)->execute($batchId, $user);
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(maxTries: 3));

        $this->assertDatabaseHas('employees', ['nip' => '198001012006041001']);
        $retryableBatch = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('queued', $retryableBatch->status);
        $this->assertSame(1, $retryableBatch->inserted_count);
        $this->assertSame(0, $retryableBatch->skipped_count);
        $this->assertSame(0, $retryableBatch->failed_count);
        $this->assertSame([], $retryableBatch->row_issues);

        $temporaryConflict->forceDelete();

        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(maxTries: 3));

        $persistedBatch = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('completed', $persistedBatch->status);
        $this->assertSame(2, $persistedBatch->inserted_count);
        $this->assertSame(0, $persistedBatch->skipped_count);
        $this->assertSame(0, $persistedBatch->failed_count);
        $this->assertSame(1, Employee::query()->where('nip', '198001012006041001')->count());
        $this->assertSame(1, Employee::query()->where('nip', '198101012007041002')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'IMPORT')->count());
        $this->assertDatabaseCount('jobs', 0);
    }

    /** Dua request eksekusi untuk batch sama hanya boleh menghasilkan satu dispatch antrean. */
    public function test_repeated_execution_request_claims_and_dispatches_batch_once(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        Queue::fake();

        $this->actingAs($user);
        $first = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);
        $second = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);

        $first->assertOk()->assertJsonPath('status', 'queued');
        $second->assertOk()->assertJsonPath('status', 'queued');
        Queue::assertPushed(ImportEmployeeBatchJob::class, 1);
        $this->assertDatabaseHas('import_batches', [
            'id' => $batchId,
            'status' => 'queued',
        ]);
    }

    /** Redelivery job setelah batch selesai tidak boleh mengubah hasil, audit, atau cache pertama. */
    public function test_repeated_execution_preserves_first_completed_result(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $this->persistQueuedBatch($batchId, $user);

        $firstResult = app(ExecuteImportBatchAction::class)->execute($batchId, $user);
        $firstBatch = ImportBatch::query()->findOrFail($batchId)->replicate();
        $firstCacheResult = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId)['result'];
        $firstAuditCount = AuditLog::query()->where('event', 'IMPORT')->count();

        $secondResult = app(ExecuteImportBatchAction::class)->execute($batchId, $user);
        $persistedBatch = ImportBatch::query()->findOrFail($batchId);

        $this->assertSame($firstResult['inserted'], $secondResult['inserted']);
        $this->assertSame($firstResult['skipped'], $secondResult['skipped']);
        $this->assertSame($firstBatch->inserted_count, $persistedBatch->inserted_count);
        $this->assertSame($firstBatch->skipped_count, $persistedBatch->skipped_count);
        $this->assertSame($firstBatch->row_issues, $persistedBatch->row_issues);
        $this->assertSame($firstCacheResult, Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId)['result']);
        $this->assertSame($firstAuditCount, AuditLog::query()->where('event', 'IMPORT')->count());
        $this->assertSame(1, Employee::query()->where('nip', '198001012006041001')->count());
    }

    /** Batch yang sudah diproses worker lain tidak boleh diklaim atau dieksekusi ulang. */
    public function test_processing_batch_cannot_be_executed_again(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $this->persistQueuedBatch($batchId, $user);
        ImportBatch::query()->whereKey($batchId)->update(['status' => 'processing']);

        app(ExecuteImportBatchAction::class)->execute($batchId, $user);

        $this->assertDatabaseMissing('employees', ['nip' => '198001012006041001']);
        $this->assertSame('processing', ImportBatch::query()->findOrFail($batchId)->status);
        $this->assertSame(0, AuditLog::query()->where('event', 'IMPORT')->count());
    }

    /** Callback gagal dari redelivery tidak boleh menimpa cache atau laporan batch yang sudah selesai. */
    public function test_late_failure_callback_preserves_completed_batch_result(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $this->persistQueuedBatch($batchId, $user);
        app(ExecuteImportBatchAction::class)->execute($batchId, $user);

        $completedCache = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);

        $job = new ImportEmployeeBatchJob($batchId, $user->id);
        $job->failed(new \RuntimeException('Kegagalan redelivery terlambat'));

        $persistedBatch = ImportBatch::query()->findOrFail($batchId);
        $currentCache = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);

        $this->assertSame('completed', $persistedBatch->status);
        $this->assertNull($persistedBatch->error_message);
        $this->assertSame('completed', $currentCache['status']);
        $this->assertSame($completedCache['result'], $currentCache['result']);
    }

    /** Kegagalan worker sebelum claim processing tetap harus membuat batch queued dapat diinspeksi sebagai gagal. */
    public function test_failure_before_processing_claim_marks_queued_batch_failed(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $this->persistQueuedBatch($batchId, $user);

        $cachedBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
        $cachedBatch['status'] = 'queued';
        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $cachedBatch, now()->addMinutes(10));

        $job = new ImportEmployeeBatchJob($batchId, $user->id);
        $job->failed(new \RuntimeException('Worker gagal sebelum claim'));

        $this->assertSame('failed', ImportBatch::query()->findOrFail($batchId)->status);
        $this->assertSame('failed', Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId)['status']);
    }

    private function validatedBatchId(User $user): string
    {
        $batch = app(UploadImportBatchAction::class)->execute(
            $this->csvFile($this->validCsv()),
            'utama',
            $user,
        );
        app(ValidateImportBatchAction::class)->execute($batch['batch_id'], null, $user);

        return $batch['batch_id'];
    }

    private function persistQueuedBatch(string $batchId, User $user): void
    {
        $batch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);

        ImportBatch::create([
            'id' => $batchId,
            'user_id' => $user->id,
            'filename' => $batch['filename'],
            'type' => $batch['type'],
            'status' => 'queued',
            'total_rows' => $batch['total_rows'],
            'valid_count' => $batch['validation']['valid_count'],
            'inserted_count' => 0,
            'skipped_count' => $batch['validation']['skip_count'],
            'failed_count' => $batch['validation']['error_count'],
            'row_issues' => [],
        ]);
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function validCsv(string $namaDenganGelar = 'Budi Santoso'): string
    {
        $headers = [
            'Nama Pegawai', 'Email Pegawai', 'Golongan', 'Jabatan', 'Kelas Jabatan', 'NIP',
            'Nomor Telepon', 'Pangkat', 'Pendidikan Terakhir', 'Pensiun', 'Person', 'Person Formula',
            'Prodi Pendidikan Terakhir', 'Status Kepegawaian', 'Tanggal Lahir',
        ];
        $row = [
            $namaDenganGelar, 'budi@example.com', 'III/a', 'Analis Kepegawaian', '7',
            '198001012006041001', '081234567890', 'Penata Muda', 'S1', '2038-01-01',
            'Budi Santoso', 'Budi Santoso', 'Manajemen', 'PNS', '1980-01-01',
        ];

        return implode(',', $headers)."\n".implode(',', $row)."\n";
    }

    private function twoRowCsv(): string
    {
        $firstRow = [
            'Budi Santoso', 'budi@example.com', 'III/a', 'Analis Kepegawaian', '7',
            '198001012006041001', '081234567890', 'Penata Muda', 'S1', '2038-01-01',
            'Budi Santoso', 'Budi Santoso', 'Manajemen', 'PNS', '1980-01-01',
        ];
        $secondRow = [
            'Siti Aminah', 'siti@example.com', 'III/b', 'Analis Kepegawaian', '8',
            '198101012007041002', '081234567891', 'Penata Muda Tk. I', 'S1', '2039-01-01',
            'Siti Aminah', 'Siti Aminah', 'Administrasi Negara', 'PNS', '1981-01-01',
        ];

        return implode(',', [
            'Nama Pegawai', 'Email Pegawai', 'Golongan', 'Jabatan', 'Kelas Jabatan', 'NIP',
            'Nomor Telepon', 'Pangkat', 'Pendidikan Terakhir', 'Pensiun', 'Person', 'Person Formula',
            'Prodi Pendidikan Terakhir', 'Status Kepegawaian', 'Tanggal Lahir',
        ])."\n".implode(',', $firstRow)."\n".implode(',', $secondRow)."\n";
    }

    private function csvFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'employees');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'employees.csv', 'text/csv', null, true);
    }
}
