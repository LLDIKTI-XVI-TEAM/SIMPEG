<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\Employee;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefUnitKerja;
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

    public function test_admin_kepegawaian_can_upload_sk_jabatan_and_kgb(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();
        $unit = RefUnitKerja::create(['nama' => 'Bagian Umum', 'jenis_unit' => 'bagian', 'level' => 2, 'is_active' => true]);
        $jabatan = RefJabatan::create(['nama' => 'Analis', 'is_active' => true]);

        $this->post("/api/v1/pegawai/{$employee->id}/berkas-sk", [
            'kategori_dokumen' => 'sk_jabatan',
            'jabatan_id' => $jabatan->id,
            'unit_kerja_id' => $unit->id,
            'tmt_jabatan' => '2024-03-01',
            'no_sk' => 'SK/JAB/2024',
            'tanggal_sk' => '2024-02-15',
            'file_sk' => $this->fakeFile(),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('document.jenis_dokumen', 'sk_jabatan');

        $this->post("/api/v1/pegawai/{$employee->id}/berkas-sk", [
            'kategori_dokumen' => 'sk_kgb',
            'gaji_pokok' => 4500000,
            'tmt_kgb' => '2024-04-01',
            'no_sk' => 'SK/KGB/2024',
            'tanggal_sk' => '2024-03-15',
            'file_sk' => $this->fakeFile(),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('document.jenis_dokumen', 'sk_kgb');
    }

    public function test_admin_kepegawaian_can_replace_sk_pengangkatan(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();
        RefJenisPegawai::firstOrCreate(['nama' => 'PNS']);

        $this->post("/api/v1/pegawai/{$employee->id}/berkas-sk", [
            'kategori_dokumen' => 'sk_pengangkatan',
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2018-01-01',
            'no_sk' => 'SK/ANGKAT/2018',
            'tanggal_sk' => '2017-12-15',
            'file_sk' => $this->fakeFile(),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('document.jenis_dokumen', 'sk_pengangkatan');

        $this->assertSame(1, $employee->appointments()->count());
    }

    public function test_admin_kepegawaian_cannot_check_impact_or_delete(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $document = $this->createBerkas();

        $this->getJson("/api/v1/pegawai/{$document->employee_id}/dokumen/{$document->id}/check-impact")
            ->assertForbidden();

        $this->deleteJson("/api/v1/pegawai/{$document->employee_id}/dokumen/{$document->id}")
            ->assertForbidden();
    }

    public function test_pimpinan_cannot_upload_sk_or_berkas(): void
    {
        $this->actingAsRole('pimpinan');
        $employee = Employee::factory()->create();
        $golongan = RefGolongan::create(['kode' => 'III/a', 'nama' => 'Penata Muda', 'urutan' => 1, 'is_active' => true]);

        $this->post("/api/v1/pegawai/{$employee->id}/berkas-sk", [
            'kategori_dokumen' => 'sk_pangkat',
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2024-01-01',
            'no_sk' => 'SK/PANGKAT/2024',
            'tanggal_sk' => '2023-12-15',
            'file_sk' => $this->fakeFile(),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->post("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
            'nama_dokumen' => 'KTP Pegawai',
            'kategori_dokumen' => 'ktp_kk',
            'berkas' => $this->fakeFile(),
        ], ['Accept' => 'application/json'])->assertForbidden();
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
