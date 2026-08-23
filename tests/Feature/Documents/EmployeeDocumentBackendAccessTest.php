<?php

namespace Tests\Feature\Documents;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeDocumentBackendAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Document::STORAGE_DISK);
        $this->seedRbac();
    }

    public function test_admin_kepegawaian_can_list_archive_documents(): void
    {
        $this->actingAsRole('admin_kepegawaian');

        $this->getJson('/api/v1/dokumen')
            ->assertOk()
            ->assertJsonPath('message', 'Daftar dokumen berhasil diambil.');
    }

    public function test_admin_kepegawaian_can_view_and_download_document(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $document = $this->createBerkas();

        $this->get(route('dokumen.show', $document->id))->assertOk();
        $this->get(route('dokumen.download', $document->id))->assertOk();
    }

    public function test_pimpinan_cannot_upload_berkas_lainnya(): void
    {
        $this->actingAsRole('pimpinan');
        $employee = Employee::factory()->create();

        $this->post("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
            'nama_dokumen' => 'KTP Pegawai',
            'kategori_dokumen' => 'ktp_kk',
            'berkas' => $this->fakeFile(),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_admin_without_employees_read_cannot_access_central_archive(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.read')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $this->actingAsRole('admin_kepegawaian');
        $document = $this->createBerkas();

        $this->get(route('dokumen'))->assertForbidden();
        $this->get(route('dokumen.show', $document->id))->assertForbidden();
        $this->get(route('dokumen.download', $document->id))->assertForbidden();
        $this->getJson('/api/v1/dokumen')->assertForbidden();
    }

    public function test_berkas_lainnya_upload_rolls_back_when_audit_fails(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();

        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::creating(function (): void {
            throw new \RuntimeException('Simulasi kegagalan audit berkas.');
        });
        $exceptionObserved = false;

        try {
            $this->withoutExceptionHandling()->post("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
                'nama_dokumen' => 'KTP Pegawai',
                'kategori_dokumen' => 'ktp_kk',
                'berkas' => $this->fakeFile(),
            ], ['Accept' => 'application/json']);
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan audit berkas.', $exception->getMessage());
            $exceptionObserved = true;
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertTrue($exceptionObserved, 'Upload berkas wajib meneruskan kegagalan audit.');
        $this->assertDatabaseCount('documents', 0);
        $this->assertEmpty(Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_berkas_lainnya_upload_writes_document_and_audit_atomically(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();

        $response = $this->post("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
            'nama_dokumen' => 'KTP Pegawai',
            'kategori_dokumen' => 'ktp_kk',
            'nomor_dokumen' => 'KTP-001',
            'berkas' => $this->fakeFile(),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('document.jenis_dokumen', 'ktp_kk');
        $documentId = $response->json('document.id');

        $this->assertDatabaseHas('documents', [
            'id' => $documentId,
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ktp_kk',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CREATE',
            'auditable_type' => 'Document',
            'auditable_id' => $documentId,
        ]);
        $document = Document::query()->findOrFail($documentId);
        Storage::disk(Document::STORAGE_DISK)->assertExists($document->file_path);
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

    private function fakeFile(): UploadedFile
    {
        return UploadedFile::fake()->create('berkas.pdf', 80, 'application/pdf');
    }
}
