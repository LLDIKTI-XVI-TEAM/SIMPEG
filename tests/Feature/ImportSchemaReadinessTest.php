<?php

namespace Tests\Feature;

use App\Exceptions\Import\ImportSchemaNotReadyException;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Import\ImportBatchSchemaReadiness;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Mengunci perilaku ketika tabel import_batches belum dimigrasikan.
 *
 * Kondisi ini pernah membuat eksekusi import gagal di tengah transaksi dan menampilkan
 * pesan SQL PostgreSQL beserta nama host/database ke pengguna.
 */
class ImportSchemaReadinessTest extends TestCase
{
    use RefreshDatabase;

    /** Kolom bebas index sehingga aman dihapus pada database test untuk mensimulasikan schema lama. */
    private const DROPPABLE_COLUMNS = ['execution_payload', 'processed_valid_count'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        // Seed RBAC agar middleware permission employees.import memiliki matrix permission.
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    public function test_checker_reports_no_missing_column_when_migrations_are_complete(): void
    {
        $readiness = app(ImportBatchSchemaReadiness::class);

        $this->assertSame([], $readiness->missingColumns());
        $this->assertTrue($readiness->isReady());
    }

    /**
     * Mengunci daftar kolom wajib checker terhadap migration dan model.
     * Bila migration atau fillable model bergeser, kegagalan muncul di sini, bukan saat import berjalan.
     */
    public function test_required_columns_are_backed_by_migration_and_model(): void
    {
        $fillable = (new ImportBatch)->getFillable();

        foreach (ImportBatchSchemaReadiness::REQUIRED_COLUMNS as $column) {
            $this->assertTrue(
                Schema::hasColumn('import_batches', $column),
                "Kolom {$column} belum dibuat oleh migration import_batches.",
            );
            $this->assertContains(
                $column,
                $fillable,
                "Kolom {$column} tidak dapat diisi oleh model ImportBatch.",
            );
        }
    }

    public function test_checker_reports_missing_columns_deterministically(): void
    {
        $this->dropDurabilityColumns();

        $missingColumns = app(ImportBatchSchemaReadiness::class)->missingColumns();

        $this->assertSame(['execution_payload', 'processed_valid_count'], $missingColumns);
        $this->assertFalse(app(ImportBatchSchemaReadiness::class)->isReady());
    }

    /** Tabel yang belum dibuat sama sekali harus dianggap belum siap sepenuhnya (fail-closed). */
    public function test_checker_treats_missing_table_as_fully_unready(): void
    {
        Schema::drop('import_batches');

        $readiness = app(ImportBatchSchemaReadiness::class);

        $this->assertFalse($readiness->isReady());
        $this->assertSame(
            ImportBatchSchemaReadiness::REQUIRED_COLUMNS,
            $readiness->missingColumns(),
        );
    }

    /**
     * Reproduksi bug: schema tanpa kolom durability harus ditolak sebelum insert,
     * bukan menghasilkan error SQL yang tampil ke pengguna.
     */
    public function test_execute_fails_fast_when_schema_columns_are_missing(): void
    {
        Queue::fake();
        $user = User::factory()->adminKepegawaian()->create();
        $this->actingAs($user);
        $batchId = $this->uploadAndValidateBatch();
        $employeeCountBefore = Employee::count();

        $this->dropDurabilityColumns();
        $response = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);

        $response->assertStatus(503);
        $response->assertExactJson(['message' => ImportSchemaNotReadyException::USER_MESSAGE]);

        $this->assertDatabaseCount('import_batches', 0);
        $this->assertSame($employeeCountBefore, Employee::count());
        Queue::assertNothingPushed();
    }

    /** Respons pengguna tidak boleh membocorkan SQL, struktur database, payload, atau stack trace. */
    public function test_schema_not_ready_response_does_not_leak_internal_detail(): void
    {
        Queue::fake();
        $user = User::factory()->adminKepegawaian()->create();
        $this->actingAs($user);
        $batchId = $this->uploadAndValidateBatch();

        $this->fakeUnreadySchema();
        $response = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);

        $response->assertStatus(503);
        $body = $response->getContent();

        foreach ([
            'SQLSTATE',
            '42703',
            'insert into',
            'Connection: pgsql',
            'Database:',
            'Host:',
            'execution_payload',
            'processing_token',
            'processed_valid_count',
            'vendor/laravel/framework',
            'QueueImportBatchAction',
            'trace',
        ] as $forbiddenFragment) {
            $this->assertStringNotContainsString($forbiddenFragment, $body);
        }

        $this->assertSame(['message'], array_keys($response->json()));
    }

    /** Operator butuh daftar kolom yang hilang di log, tanpa payload atau identitas pegawai. */
    public function test_schema_not_ready_is_logged_with_safe_context(): void
    {
        Queue::fake();
        $logSpy = Log::spy();
        $user = User::factory()->adminKepegawaian()->create();
        $this->actingAs($user);
        $batchId = $this->uploadAndValidateBatch();

        $this->fakeUnreadySchema(['processed_valid_count']);
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", [])->assertStatus(503);

        $logSpy->shouldHaveReceived(
            'warning',
            fn (string $message, array $context): bool => $context['feature'] === 'import.pegawai.schema_readiness'
                && $context['table'] === 'import_batches'
                && $context['missing_columns'] === ['processed_valid_count']
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'execution_payload'),
        );
    }

    /** Guard schema tidak boleh menggantikan gate role: role tanpa izin tetap ditolak lebih dulu. */
    public function test_schema_guard_does_not_weaken_role_authorization(): void
    {
        Queue::fake();
        $owner = User::factory()->adminKepegawaian()->create();
        $this->actingAs($owner);
        $batchId = $this->uploadAndValidateBatch();

        $this->fakeUnreadySchema();
        $this->actingAs(User::factory()->pegawai()->create());

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", [])->assertForbidden();
        Queue::assertNothingPushed();
    }

    /** Batch milik user lain tetap ditolak walaupun schema sudah siap. */
    public function test_execute_rejects_batch_owned_by_another_user(): void
    {
        Queue::fake();
        $owner = User::factory()->adminKepegawaian()->create();
        $this->actingAs($owner);
        $batchId = $this->uploadAndValidateBatch();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", [])->assertForbidden();
        $this->assertDatabaseCount('import_batches', 0);
        Queue::assertNothingPushed();
    }

    /** Alur normal tidak berubah: schema lengkap tetap mengantrekan batch dan menyimpan pegawai. */
    public function test_execute_still_queues_import_when_schema_is_ready(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $this->actingAs($user);
        $batchId = $this->uploadAndValidateBatch();

        $response = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);

        $response->assertOk();
        $response->assertJsonPath('status', 'queued');
        $this->assertDatabaseHas('import_batches', ['id' => $batchId, 'user_id' => $user->id]);
        $this->assertDatabaseHas('employees', ['nip' => '198001012006041001']);
    }

    /** Mengganti checker dengan implementasi yang melaporkan kolom hilang, tanpa menyentuh schema. */
    private function fakeUnreadySchema(array $missingColumns = self::DROPPABLE_COLUMNS): void
    {
        $fake = new class($missingColumns) extends ImportBatchSchemaReadiness
        {
            /** @param list<string> $missingColumns */
            public function __construct(private readonly array $missingColumns) {}

            public function missingColumns(): array
            {
                return $this->missingColumns;
            }
        };

        $this->instance(ImportBatchSchemaReadiness::class, $fake);
    }

    /**
     * Menghapus kolom durability pada database test yang terisolasi untuk meniru schema sebelum migration.
     * RefreshDatabase mengembalikan perubahan ini setelah test selesai.
     */
    private function dropDurabilityColumns(): void
    {
        Schema::table('import_batches', function ($table): void {
            $table->dropColumn(self::DROPPABLE_COLUMNS);
        });
    }

    /** Menyiapkan satu batch valid melalui endpoint wizard sehingga state batch identik dengan pemakaian nyata. */
    private function uploadAndValidateBatch(): string
    {
        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->csvFile(),
        ]);
        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", [])
            ->assertOk()
            ->assertJsonPath('valid_count', 1);

        return $batchId;
    }

    private function csvFile(): UploadedFile
    {
        $content = implode(',', [
            'Nama Pegawai',
            'Email Pegawai',
            'Golongan',
            'Jabatan',
            'Kelas Jabatan',
            'NIP',
            'Nomor Telepon',
            'Pangkat',
            'Pendidikan Terakhir',
            'Pensiun',
            'Person',
            'Person Formula',
            'Prodi Pendidikan Terakhir',
            'Status Kepegawaian',
            'Tanggal Lahir',
        ])."\n".implode(',', [
            'Budi Santoso',
            'budi@example.com',
            'III/a',
            'Analis Kepegawaian',
            '7',
            '198001012006041001',
            '081234567890',
            'Penata Muda',
            'S1',
            '2038-01-01',
            'Budi',
            'Budi',
            'Manajemen',
            'PNS',
            '1980-01-01',
        ])."\n";

        $path = tempnam(sys_get_temp_dir(), 'import-readiness');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'employees.csv', 'text/csv', null, true);
    }

    private function postJsonWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
