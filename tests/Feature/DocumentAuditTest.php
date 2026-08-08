<?php

namespace Tests\Feature;

use App\Actions\Documents\DeleteDocumentAction;
use App\Actions\Documents\StoreDocumentAction;
use App\Actions\Documents\UpdateDocumentAction;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Arsip dokumen memuat berkas kepegawaian resmi seperti SK pangkat dan SK jabatan, sehingga
 * penambahan, perubahan, maupun penghapusannya wajib meninggalkan jejak audit. Payload audit
 * sengaja menyimpan metadata dokumen saja, bukan isi berkas.
 */
class DocumentAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Document::STORAGE_DISK);
    }

    public function test_unggah_dokumen_tercatat_pada_audit(): void
    {
        $employee = Employee::factory()->create();

        $document = app(StoreDocumentAction::class)->execute([
            'pegawai_id' => $employee->id,
            'kategori_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Kenaikan Pangkat',
            'nomor_dokumen' => 'SK/001/2026',
            'tanggal_terbit' => '2026-01-02',
        ], UploadedFile::fake()->create('sk-pangkat.pdf', 64, 'application/pdf'));

        $audit = AuditLog::query()
            ->where('event', 'CREATE')
            ->where('auditable_type', 'Document')
            ->where('auditable_id', $document->id)
            ->sole();

        $this->assertNull($audit->old_values);
        $this->assertSame($employee->id, $audit->new_values['employee_id']);
        $this->assertSame('sk_pangkat', $audit->new_values['jenis_dokumen']);
        $this->assertSame('SK Kenaikan Pangkat', $audit->new_values['nama_dokumen']);
        $this->assertSame('SK/001/2026', $audit->new_values['nomor_dokumen']);
        $this->assertSame($document->file_path, $audit->new_values['file_path']);
    }

    public function test_perubahan_metadata_dokumen_tercatat_pada_audit(): void
    {
        $employee = Employee::factory()->create();
        $document = app(StoreDocumentAction::class)->execute([
            'pegawai_id' => $employee->id,
            'kategori_dokumen' => 'sk_jabatan',
            'nama_dokumen' => 'SK Jabatan Lama',
            'nomor_dokumen' => 'SK/002/2026',
            'tanggal_terbit' => '2026-01-03',
        ], UploadedFile::fake()->create('sk-jabatan.pdf', 64, 'application/pdf'));

        app(UpdateDocumentAction::class)->execute($document, [
            'kategori_dokumen' => 'sk_jabatan',
            'nama_dokumen' => 'SK Jabatan Baru',
            'nomor_dokumen' => 'SK/002/2026',
            'tanggal_terbit' => '2026-01-03',
        ]);

        $audit = AuditLog::query()
            ->where('event', 'UPDATE')
            ->where('auditable_type', 'Document')
            ->where('auditable_id', $document->id)
            ->sole();

        $this->assertSame('SK Jabatan Lama', $audit->old_values['nama_dokumen']);
        $this->assertSame('SK Jabatan Baru', $audit->new_values['nama_dokumen']);
    }

    public function test_penghapusan_dokumen_tercatat_pada_audit(): void
    {
        $employee = Employee::factory()->create();
        $document = app(StoreDocumentAction::class)->execute([
            'pegawai_id' => $employee->id,
            'kategori_dokumen' => 'ijazah',
            'nama_dokumen' => 'Ijazah S1',
            'nomor_dokumen' => 'IJZ/003/2026',
            'tanggal_terbit' => '2026-01-04',
        ], UploadedFile::fake()->create('ijazah.pdf', 64, 'application/pdf'));

        app(DeleteDocumentAction::class)->execute($document);

        $audit = AuditLog::query()
            ->where('event', 'DELETE')
            ->where('auditable_type', 'Document')
            ->where('auditable_id', $document->id)
            ->sole();

        // Metadata dokumen wajib terekam pada sisi lama karena barisnya sudah tidak ada lagi
        // setelah penghapusan, sehingga audit adalah satu-satunya keterangan yang tersisa.
        $this->assertSame('Ijazah S1', $audit->old_values['nama_dokumen']);
        $this->assertSame('ijazah', $audit->old_values['jenis_dokumen']);
        $this->assertSame($employee->id, $audit->old_values['employee_id']);
        $this->assertNull($audit->new_values);
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    }
}
