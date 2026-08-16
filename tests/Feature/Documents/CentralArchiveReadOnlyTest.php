<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Arsip dokumen terpusat bersifat baca-saja (K-MTG-04): seluruh mutasi web
 * ditolak untuk kedua role pengelola dan halaman tidak menawarkan aksi mutasi.
 */
class CentralArchiveReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Document::STORAGE_DISK);
        $this->seedRbac();
    }

    public function test_admin_kepegawaian_cannot_upload_via_central_archive(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();

        $this->post('/dashboard/dokumen/upload', [
            'nama_dokumen' => 'Dicoba dari arsip',
            'kategori_dokumen' => 'lainnya',
            'pegawai_id' => $employee->id,
            'berkas' => UploadedFile::fake()->create('coba.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_super_admin_cannot_upload_via_central_archive(): void
    {
        $this->actingAsRole('super_admin');
        $employee = Employee::factory()->create();

        $this->post('/dashboard/dokumen/upload', [
            'nama_dokumen' => 'Dicoba dari arsip',
            'kategori_dokumen' => 'lainnya',
            'pegawai_id' => $employee->id,
            'berkas' => UploadedFile::fake()->create('coba.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_admin_kepegawaian_cannot_update_via_central_archive(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $document = $this->createBerkas();

        $this->post("/dashboard/dokumen/{$document->id}", [
            'nama_dokumen' => 'Ubah dari arsip',
            'kategori_dokumen' => 'lainnya',
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame('KTP Pegawai', $document->refresh()->nama_dokumen);
    }

    public function test_super_admin_cannot_delete_via_central_archive(): void
    {
        $this->actingAsRole('super_admin');
        $document = $this->createBerkas();

        $this->delete("/dashboard/dokumen/{$document->id}", [], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    public function test_super_admin_cannot_check_impact_via_central_archive(): void
    {
        $this->actingAsRole('super_admin');
        $document = $this->createBerkas();

        $this->getJson("/dashboard/dokumen/{$document->id}/check-impact")
            ->assertForbidden();
    }

    public function test_read_only_surface_still_available_for_admin(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $document = $this->createBerkas();

        $this->get(route('dokumen'))->assertOk();
        $this->get(route('dokumen.show', $document->id))->assertOk();
        $this->get(route('dokumen.download', $document->id))->assertOk();
    }

    private function createBerkas(): Document
    {
        $employee = Employee::factory()->create();
        $path = "{$employee->id}/ktp_kk/ktp.pdf";
        Storage::disk(Document::STORAGE_DISK)->put($path, 'ktp');

        return Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ktp_kk',
            'nama_dokumen' => 'KTP Pegawai',
            'file_path' => $path,
        ]);
    }
}
