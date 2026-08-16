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

    public function test_admin_kepegawaian_sees_dokumen_sk_upload_actions(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();

        $this->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('Dokumen SK')
            ->assertSee('Tambah Berkas SK')
            ->assertSee('Unggah Berkas')
            ->assertSee('Hapus berkas hanya bisa dilakukan Super Admin')
            ->assertDontSee('>Hapus Berkas<', false);
    }

    public function test_super_admin_can_delete_berkas_from_dokumen_sk_tab(): void
    {
        $this->actingAsRole('super_admin');
        $employee = Employee::factory()->create();

        $this->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('Tambah Berkas SK')
            ->assertSee('Hapus Berkas')
            ->assertDontSee('Hapus berkas hanya bisa dilakukan Super Admin');
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
}
