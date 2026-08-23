<?php

namespace Tests\Feature\Documents;

use App\Jobs\CleanupEmployeeDocumentFileJob;
use App\Models\Document;
use App\Models\Employee;
use App\Support\Documents\BerkasLainnyaMutationGuard;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Disk employee_documents dikonfigurasi throw => false sehingga kegagalan penulisan
 * dikembalikan sebagai false, bukan exception. Update wajib ditolak sebelum transaksi
 * berjalan agar metadata tidak menunjuk path kosong dan file lama ikut terhapus.
 */
class BerkasLainnyaStorageFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Document::STORAGE_DISK);
        $this->seedRbac();
    }

    public function test_update_menolak_penggantian_berkas_ketika_penulisan_disk_gagal(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $document = $this->createBerkas();
        $oldPath = $document->file_path;
        $filesBeforeRequest = Storage::disk(Document::STORAGE_DISK)->allFiles();

        $this->failNextPut();
        $response = $this->post("/api/v1/pegawai/{$document->employee_id}/berkas-lainnya/{$document->id}", [
            '_method' => 'PUT',
            'nama_dokumen' => 'Harus Gagal',
            'kategori_dokumen' => 'lainnya',
            'berkas' => UploadedFile::fake()->create('pengganti.pdf', 60, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertUnprocessable()->assertJsonValidationErrors('berkas');

        $document->refresh();
        $this->assertSame('Surat Keterangan', $document->nama_dokumen);
        $this->assertSame($oldPath, $document->file_path);
        Storage::disk(Document::STORAGE_DISK)->assertExists($oldPath);
        $this->assertSame(
            $filesBeforeRequest,
            Storage::disk(Document::STORAGE_DISK)->allFiles(),
            'Tidak boleh ada file sisa dari percobaan penggantian yang gagal.'
        );
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'UPDATE',
            'auditable_id' => $document->id,
        ]);
    }

    public function test_upload_menolak_berkas_baru_ketika_penulisan_disk_gagal(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();

        $this->failNextPut();
        $response = $this->post("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
            'nama_dokumen' => 'Upload Harus Gagal',
            'kategori_dokumen' => 'lainnya',
            'berkas' => UploadedFile::fake()->create('baru.pdf', 60, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertUnprocessable()->assertJsonValidationErrors('berkas');

        $this->assertDatabaseCount('documents', 0);
        $this->assertEmpty(Storage::disk(Document::STORAGE_DISK)->allFiles());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'CREATE']);
    }

    public function test_delete_menjadwalkan_cleanup_ketika_penghapusan_disk_gagal(): void
    {
        Queue::fake();
        $this->actingAsRole('admin_kepegawaian');
        $document = $this->createBerkas();
        $path = $document->file_path;

        Log::shouldReceive('warning')->once()->with(
            'Penghapusan file dokumen pegawai ditunda karena storage gagal.',
            ['file_path_hash' => hash('sha256', $path)],
        );

        $this->failNextDelete();

        $this->deleteJson("/api/v1/pegawai/{$document->employee_id}/berkas-lainnya/{$document->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Berkas berhasil dihapus.');

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        Storage::disk(Document::STORAGE_DISK)->assertExists($path);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_id' => $document->id,
        ]);
        Queue::assertPushed(
            CleanupEmployeeDocumentFileJob::class,
            fn (CleanupEmployeeDocumentFileJob $job): bool => $job->filePath === $path,
        );
    }

    public function test_job_cleanup_menghapus_file_yang_tidak_lagi_direferensikan(): void
    {
        $document = $this->createBerkas();
        $path = $document->file_path;
        $document->delete();

        $job = new CleanupEmployeeDocumentFileJob($path);
        $job->handle(app(BerkasLainnyaMutationGuard::class));

        Storage::disk(Document::STORAGE_DISK)->assertMissing($path);
    }

    public function test_job_cleanup_mempertahankan_file_yang_kembali_direferensikan(): void
    {
        $document = $this->createBerkas();

        $job = new CleanupEmployeeDocumentFileJob($document->file_path);
        $job->handle(app(BerkasLainnyaMutationGuard::class));

        Storage::disk(Document::STORAGE_DISK)->assertExists($document->file_path);
    }

    public function test_job_cleanup_melempar_saat_delete_gagal_agar_queue_mencoba_ulang(): void
    {
        $document = $this->createBerkas();
        $path = $document->file_path;
        $document->delete();
        $this->failNextDelete();
        $job = new CleanupEmployeeDocumentFileJob($path);

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pembersihan file dokumen pegawai gagal.');

        $job->handle(app(BerkasLainnyaMutationGuard::class));
    }

    public function test_job_cleanup_melaporkan_kegagalan_final_tanpa_path_mentah(): void
    {
        $path = 'pegawai-rahasia/lainnya/berkas.pdf';
        $exception = new RuntimeException('Detail I/O internal');
        Log::shouldReceive('error')->once()->with(
            'Pembersihan file dokumen pegawai gagal setelah retry maksimum.',
            [
                'file_path_hash' => hash('sha256', $path),
                'exception_class' => RuntimeException::class,
            ],
        );

        (new CleanupEmployeeDocumentFileJob($path))->failed($exception);
    }

    /**
     * Ganti disk arsip dengan versi yang selalu gagal menulis seperti storage penuh:
     * putFileAs() mengembalikan false tanpa melempar exception. Method lain tetap
     * didelegasikan ke disk asli agar pemeriksaan file pada pengujian tetap jalan.
     */
    private function failNextPut(): void
    {
        $original = Storage::disk(Document::STORAGE_DISK);
        $failingDisk = new class($original)
        {
            public function __construct(private readonly FilesystemAdapter $inner) {}

            public function put($path, $contents, $options = []): bool
            {
                return false;
            }

            public function putFileAs($path, $file, $name = null, $options = [])
            {
                // Meniru perilaku Flysystem saat penulisan gagal pada throw => false.
                return false;
            }

            public function __call($method, $parameters)
            {
                return $this->inner->{$method}(...$parameters);
            }
        };

        Storage::set(Document::STORAGE_DISK, $failingDisk);
    }

    /** Ganti disk dengan adapter yang meniru delete() gagal pada throw => false. */
    private function failNextDelete(): void
    {
        $original = Storage::disk(Document::STORAGE_DISK);
        $failingDisk = new class($original)
        {
            public function __construct(private readonly FilesystemAdapter $inner) {}

            public function delete($paths): bool
            {
                return false;
            }

            public function __call($method, $parameters)
            {
                return $this->inner->{$method}(...$parameters);
            }
        };

        Storage::set(Document::STORAGE_DISK, $failingDisk);
    }

    private function createBerkas(): Document
    {
        $employee = Employee::factory()->create();
        $path = $employee->id.'/lainnya/surat.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($path, 'isi');

        return Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Surat Keterangan',
            'file_path' => $path,
        ]);
    }
}
