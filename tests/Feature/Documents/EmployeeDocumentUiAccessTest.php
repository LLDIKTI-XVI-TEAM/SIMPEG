<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeDocumentUiAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Document::STORAGE_DISK);
        $this->seedRbac();
    }

    public function test_admin_kepegawaian_can_open_archive_as_read_only(): void
    {
        $this->actingAsRole('admin_kepegawaian');

        $this->get(route('dokumen'))
            ->assertOk()
            ->assertSee('Arsip Dokumen Kepegawaian')
            ->assertSee('Buka Data Pegawai')
            ->assertDontSee('Unggah Dokumen Baru');
    }

    public function test_admin_kepegawaian_sees_separated_tables_and_additional_document_modal(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();

        $this->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('Dokumen SK')
            ->assertSee('Berkas Lainnya')
            ->assertSee('Unggah Berkas Lainnya')
            ->assertSee('Ubah Berkas Lainnya')
            ->assertSee('Hapus Berkas Lainnya')
            ->assertSee('Simpan Perubahan')
            ->assertSee('Hapus Berkas')
            ->assertSee('Form ini hanya untuk dokumen tambahan')
            ->assertDontSee('Tambah Berkas SK')
            ->assertDontSee('Ganti Berkas SK');
    }

    public function test_pimpinan_cannot_open_archive_or_admin_employee_detail(): void
    {
        $this->actingAsRole('pimpinan');
        $employee = Employee::factory()->create();

        $this->get(route('dokumen'))->assertForbidden();
        $this->get(route('pegawai.show', $employee->id))->assertForbidden();
    }

    public function test_admin_kepegawaian_can_upload_berkas_lainnya(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();

        $this->post("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
            'nama_dokumen' => 'KTP Pegawai',
            'kategori_dokumen' => 'ktp_kk',
            'nomor_dokumen' => '1234567890',
            'tanggal_terbit' => '2024-01-01',
            'keterangan' => 'KTP aktif',
            'berkas' => UploadedFile::fake()->create('ktp.pdf', 80, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('document.jenis_dokumen', 'ktp_kk');
    }

    public function test_document_archive_and_profile_views_contain_cache_invalidation_contracts(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();

        $this->get(route('dokumen'))
            ->assertOk()
            ->assertSee('_cacheTTL')
            ->assertSee('_mutationKey')
            ->assertSee('getCachedPage');

        $this->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('clearDocumentArchiveCache');
    }
}
