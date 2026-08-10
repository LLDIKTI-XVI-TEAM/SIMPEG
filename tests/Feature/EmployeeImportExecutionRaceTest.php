<?php

namespace Tests\Feature;

use App\Actions\Employees\ExecuteImportBatchAction;
use App\Actions\Employees\UploadImportBatchAction;
use App\Actions\Employees\ValidateImportBatchAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        $result = app(ExecuteImportBatchAction::class)->execute($batchId, $user);

        $this->assertDatabaseCount('employees', $employeeCountBeforeRace + 1);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['inserted_count']);
        $this->assertSame(1, $result['skipped_count']);

        $persistedBatch = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame($result['inserted_count'], $persistedBatch->inserted_count);
        $this->assertSame($result['skipped_count'], $persistedBatch->skipped_count);
        $this->assertSame('dilewati', $persistedBatch->row_issues[0]['kategori']);
        $this->assertSame(2, $persistedBatch->row_issues[0]['row']);
        $this->assertArrayHasKey('NIP', $persistedBatch->row_issues[0]['errors']);

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

    /** Pelanggaran unique selain NIP harus tetap menggagalkan batch dan tidak disamarkan sebagai skip. */
    public function test_execution_does_not_classify_another_unique_constraint_as_duplicate_nip(): void
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

        DB::statement(
            'CREATE UNIQUE INDEX employee_import_race_email_unique '
            .'ON employees (LOWER(email_pribadi)) WHERE email_pribadi IS NOT NULL',
        );
        Employee::factory()->create([
            'nip' => '199001012015041003',
            'email_pribadi' => 'budi@example.com',
        ]);

        try {
            app(ExecuteImportBatchAction::class)->execute($batchId, $user);
            $this->fail('Unique violation email seharusnya dilempar ulang.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('employee_import_race_email_unique', $exception->getMessage());
        }

        $persistedBatch = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('failed', $persistedBatch->status);
        $this->assertSame(0, $persistedBatch->inserted_count);
        $this->assertSame(0, $persistedBatch->skipped_count);
    }

    private function validCsv(): string
    {
        $headers = [
            'Nama Pegawai', 'Email Pegawai', 'Golongan', 'Jabatan', 'Kelas Jabatan', 'NIP',
            'Nomor Telepon', 'Pangkat', 'Pendidikan Terakhir', 'Pensiun', 'Person', 'Person Formula',
            'Prodi Pendidikan Terakhir', 'Status Kepegawaian', 'Tanggal Lahir',
        ];
        $row = [
            'Budi Santoso', 'budi@example.com', 'III/a', 'Analis Kepegawaian', '7',
            '198001012006041001', '081234567890', 'Penata Muda', 'S1', '2038-01-01',
            'Budi Santoso', 'Budi Santoso', 'Manajemen', 'PNS', '1980-01-01',
        ];

        return implode(',', $headers)."\n".implode(',', $row)."\n";
    }

    private function csvFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'employees');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'employees.csv', 'text/csv', null, true);
    }
}
