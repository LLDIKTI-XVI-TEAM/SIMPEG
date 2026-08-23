<?php

namespace Tests\Feature\Documents;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\RefJenjangPendidikan;
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

    public function test_pimpinan_cannot_update_or_delete_berkas_lainnya(): void
    {
        $this->actingAsRole('pimpinan');
        $document = $this->createBerkas();

        $this->putJson("/api/v1/pegawai/{$document->employee_id}/berkas-lainnya/{$document->id}", [
            'nama_dokumen' => 'Dokumen Diubah',
            'kategori_dokumen' => 'lainnya',
        ])->assertForbidden();
        $this->deleteJson("/api/v1/pegawai/{$document->employee_id}/berkas-lainnya/{$document->id}")
            ->assertForbidden();
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

    public function test_admin_without_employees_update_cannot_mutate_berkas_lainnya(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.update')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $this->actingAsRole('admin_kepegawaian');
        $document = $this->createBerkas();

        $this->putJson("/api/v1/pegawai/{$document->employee_id}/berkas-lainnya/{$document->id}", [
            'nama_dokumen' => 'Tidak Boleh Berubah',
            'kategori_dokumen' => 'lainnya',
        ])->assertForbidden();
        $this->deleteJson("/api/v1/pegawai/{$document->employee_id}/berkas-lainnya/{$document->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'nama_dokumen' => 'Surat Keterangan',
        ]);
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

    public function test_admin_can_update_metadata_and_replace_private_file_with_audit(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $document = $this->createBerkas();
        $oldPath = $document->file_path;

        $response = $this->post("/api/v1/pegawai/{$document->employee_id}/berkas-lainnya/{$document->id}", [
            '_method' => 'PUT',
            'nama_dokumen' => 'Surat Keterangan Terbaru',
            'kategori_dokumen' => 'ktp_kk',
            'nomor_dokumen' => 'KTP-UPDATED',
            'tanggal_terbit' => '2026-08-23',
            'keterangan' => 'Metadata diperbaiki',
            'berkas' => UploadedFile::fake()->create('ktp-baru.pdf', 90, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('message', 'Berkas berhasil diperbarui.')
            ->assertJsonPath('document.nama_dokumen', 'Surat Keterangan Terbaru')
            ->assertJsonPath('document.jenis_dokumen', 'ktp_kk')
            ->assertJsonPath('document.can_mutate', true)
            ->assertJsonMissingPath('document.file_path');

        $updated = $document->refresh();
        $this->assertNotSame($oldPath, $updated->file_path);
        Storage::disk(Document::STORAGE_DISK)->assertMissing($oldPath);
        Storage::disk(Document::STORAGE_DISK)->assertExists($updated->file_path);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'Document',
            'auditable_id' => $document->id,
        ]);
    }

    public function test_admin_can_delete_standalone_berkas_lainnya_with_audit(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $document = $this->createBerkas();
        $path = $document->file_path;

        $this->deleteJson("/api/v1/pegawai/{$document->employee_id}/berkas-lainnya/{$document->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Berkas berhasil dihapus.')
            ->assertJsonPath('document_id', $document->id);

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        Storage::disk(Document::STORAGE_DISK)->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'Document',
            'auditable_id' => $document->id,
        ]);
    }

    public function test_employee_scoped_routes_hide_document_owned_by_another_employee(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $document = $this->createBerkas();
        $otherEmployee = Employee::factory()->create();

        $this->putJson("/api/v1/pegawai/{$otherEmployee->id}/berkas-lainnya/{$document->id}", [
            'nama_dokumen' => 'Tidak Boleh Berubah',
            'kategori_dokumen' => 'lainnya',
        ])->assertNotFound();
        $this->deleteJson("/api/v1/pegawai/{$otherEmployee->id}/berkas-lainnya/{$document->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'employee_id' => $document->employee_id,
            'nama_dokumen' => 'Surat Keterangan',
        ]);
    }

    public function test_sk_and_history_linked_ijazah_cannot_be_mutated_as_berkas_lainnya(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();
        $sk = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Pangkat',
            'file_path' => $employee->id.'/sk_pangkat/sk.pdf',
        ]);
        $ijazah = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ijazah',
            'nama_dokumen' => 'Ijazah S1',
            'file_path' => $employee->id.'/ijazah/ijazah.pdf',
        ]);
        $jenjang = RefJenjangPendidikan::create(['nama' => 'S1', 'urutan' => 6, 'is_active' => true]);
        EducationHistory::create([
            'employee_id' => $employee->id,
            'jenjang_id' => $jenjang->id,
            'nama_institusi' => 'Universitas Pengujian',
            'tahun_lulus' => 2020,
            'no_ijazah' => 'IJZ-001',
            'file_ijazah' => $ijazah->file_path,
        ]);

        $payload = ['nama_dokumen' => 'Percobaan Ubah', 'kategori_dokumen' => 'ijazah'];
        $this->putJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya/{$sk->id}", $payload)
            ->assertForbidden();
        $this->putJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya/{$ijazah->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document');
        $this->deleteJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya/{$ijazah->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document');

        $this->assertDatabaseHas('documents', ['id' => $sk->id]);
        $this->assertDatabaseHas('documents', ['id' => $ijazah->id, 'nama_dokumen' => 'Ijazah S1']);
    }

    public function test_update_and_delete_roll_back_when_audit_fails(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $updateDocument = $this->createBerkas();
        $deleteDocument = $this->createBerkas();
        $oldUpdatePath = $updateDocument->file_path;
        $deletePath = $deleteDocument->file_path;
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::creating(function (): void {
            throw new \RuntimeException('Simulasi kegagalan audit mutasi berkas.');
        });

        try {
            $this->withoutExceptionHandling()->post("/api/v1/pegawai/{$updateDocument->employee_id}/berkas-lainnya/{$updateDocument->id}", [
                '_method' => 'PUT',
                'nama_dokumen' => 'Perubahan Harus Batal',
                'kategori_dokumen' => 'lainnya',
                'berkas' => UploadedFile::fake()->create('pengganti.pdf', 70, 'application/pdf'),
            ], ['Accept' => 'application/json']);
            $this->fail('Update harus melempar ketika audit gagal.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan audit mutasi berkas.', $exception->getMessage());
        }

        try {
            $this->withoutExceptionHandling()->delete("/api/v1/pegawai/{$deleteDocument->employee_id}/berkas-lainnya/{$deleteDocument->id}", [], ['Accept' => 'application/json']);
            $this->fail('Delete harus melempar ketika audit gagal.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan audit mutasi berkas.', $exception->getMessage());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseHas('documents', [
            'id' => $updateDocument->id,
            'nama_dokumen' => 'Surat Keterangan',
            'file_path' => $oldUpdatePath,
        ]);
        $this->assertDatabaseHas('documents', ['id' => $deleteDocument->id]);
        Storage::disk(Document::STORAGE_DISK)->assertExists($oldUpdatePath);
        Storage::disk(Document::STORAGE_DISK)->assertExists($deletePath);
        $this->assertCount(2, Storage::disk(Document::STORAGE_DISK)->allFiles());
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
