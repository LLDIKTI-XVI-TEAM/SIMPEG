<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\Employee;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
