<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeImportReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_execution_persists_batch_report_and_report_survives_cache_expiry(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        // NIP baris kedua sudah terdaftar sehingga baris tersebut di-skip.
        Employee::factory()->create(['nip' => '198502122010042002']);

        $this->actingAs($user);

        $upload = $this->postJsonWithCsrf('/api/pegawai/import/upload', [
            'file' => $this->csvFile($this->mixedCsv()),
            'type' => 'utama',
        ]);
        $upload->assertOk();
        $batchId = $upload->json('batch_id');

        // NIP duplikat database tertangkap rule unique sehingga berkategori error, bukan skip.
        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/validate", [])
            ->assertOk()
            ->assertJsonPath('valid_count', 1)
            ->assertJsonPath('skip_count', 0)
            ->assertJsonPath('error_count', 2);

        $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", [])->assertOk();

        $this->getJson("/api/pegawai/import/{$batchId}/status")
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('import_batches', [
            'id' => $batchId,
            'user_id' => $user->id,
            'status' => 'completed',
            'total_rows' => 3,
            'valid_count' => 1,
            'inserted_count' => 1,
            'skipped_count' => 0,
            'failed_count' => 2,
        ]);
        $this->assertDatabaseHas('employees', ['nip' => '198001012006041001']);

        // Laporan harus tetap bisa diunduh walaupun cache wizard sudah hilang.
        Cache::flush();

        $report = $this->get("/pegawai/import/{$batchId}/laporan");
        $report->assertOk();
        $this->assertStringContainsString('text/csv', (string) $report->headers->get('content-type'));

        $csv = $report->streamedContent();
        $this->assertStringContainsString('Laporan Hasil Import Pegawai', $csv);
        $this->assertStringContainsString('"Berhasil ditambahkan",1', $csv);
        $this->assertStringContainsString('"Gagal validasi",2', $csv);
        $this->assertStringContainsString('gagal', $csv);
        $this->assertStringContainsString('Siti Aminah', $csv);
        $this->assertStringContainsString('NIP', $csv);
        $this->assertStringContainsString('Tanggal Lahir', $csv);
    }

    public function test_report_is_forbidden_for_admin_who_does_not_own_the_batch(): void
    {
        $owner = User::factory()->adminKepegawaian()->create();
        $batch = ImportBatch::create([
            'id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'filename' => 'employees.csv',
            'type' => 'utama',
            'status' => 'completed',
        ]);

        $this->actingAs(User::factory()->adminKepegawaian()->create());

        $this->get("/pegawai/import/{$batch->id}/laporan")->assertForbidden();
    }

    public function test_report_is_forbidden_for_pegawai_role(): void
    {
        $batch = ImportBatch::create([
            'id' => (string) Str::uuid(),
            'user_id' => null,
            'filename' => 'employees.csv',
            'type' => 'utama',
            'status' => 'completed',
        ]);

        $this->actingAs(User::factory()->pegawai()->create());

        $this->get("/pegawai/import/{$batch->id}/laporan")->assertForbidden();
    }

    public function test_report_returns_404_for_unknown_batch(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create());

        $this->get('/pegawai/import/'.Str::uuid().'/laporan')->assertNotFound();
    }

    /**
     * Tiga baris: valid, NIP duplikat database (error unique), dan tanggal lahir tidak valid (error).
     */
    private function mixedCsv(): string
    {
        $headers = [
            'Nama Pegawai', 'Email Pegawai', 'Golongan', 'Jabatan', 'Kelas Jabatan', 'NIP',
            'Nomor Telepon', 'Pangkat', 'Pendidikan Terakhir', 'Pensiun', 'Person', 'Person Formula',
            'Prodi Pendidikan Terakhir', 'Status Kepegawaian', 'Tanggal Lahir', 'Role',
        ];

        $rows = [
            ['Budi Santoso', 'budi@example.com', 'III/a', 'Analis Kepegawaian', '7', '198001012006041001',
                '081234567890', 'Penata Muda', 'S1', '2038-01-01', 'Budi', 'Budi', 'Manajemen', 'PNS', '1980-01-01', 'pegawai'],
            ['Siti Aminah', 'siti@example.com', 'III/b', 'Pranata Komputer', '8', '198502122010042002',
                '081298765432', 'Penata Muda Tingkat I', 'S2', '2043-02-12', 'Siti', 'Siti', 'Teknik Informatika', 'PNS', '1985-02-12', 'pegawai'],
            ['Joko Tidak Valid', 'joko@example.com', 'III/a', 'Analis Kepegawaian', '7', '199001012015041003',
                '081277788899', 'Penata Muda', 'S1', '', 'Joko', 'Joko', 'Akuntansi', 'PNS', 'bukan-tanggal', 'pegawai'],
        ];

        return implode(',', $headers)."\n".implode("\n", array_map(fn (array $row) => implode(',', $row), $rows))."\n";
    }

    private function csvFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'employees');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'employees.csv', 'text/csv', null, true);
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
