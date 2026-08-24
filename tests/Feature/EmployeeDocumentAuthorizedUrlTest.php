<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression kontrak storage privat: URL berkas dokumen pegawai wajib memakai
 * route unduh terotorisasi dan tidak boleh mengekspos path publik /storage.
 */
class EmployeeDocumentAuthorizedUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_additional_document_upload_returns_authorized_archive_urls(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
                'nama_dokumen' => 'Ijazah Privat',
                'kategori_dokumen' => 'ijazah',
                'berkas' => UploadedFile::fake()->create('ijazah.pdf', 10, 'application/pdf'),
            ])
            ->assertCreated();

        $document = Document::query()->findOrFail($response->json('document.id'));

        $this->assertSame(route('dokumen.show', $document), $response->json('document.detail_url'));
        $this->assertSame(route('dokumen.download', $document), $response->json('document.download_url'));
        $this->assertStringNotContainsString('/storage/', $response->getContent());
    }

    public function test_document_upload_never_lands_on_public_disk(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake('public');
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
                'nama_dokumen' => 'Ijazah Privat',
                'kategori_dokumen' => 'ijazah',
                'berkas' => UploadedFile::fake()->create('ijazah.pdf', 10, 'application/pdf'),
            ])
            ->assertCreated();

        $document = Document::query()
            ->where('employee_id', $employee->id)
            ->where('jenis_dokumen', 'ijazah')
            ->firstOrFail();

        Storage::disk(Document::STORAGE_DISK)->assertExists($document->file_path);
        Storage::disk('public')->assertMissing($document->file_path);
    }
}
