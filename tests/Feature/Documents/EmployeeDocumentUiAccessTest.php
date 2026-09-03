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

        $this->get(route('rbac.pegawai.show', $employee->id))
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
            ->assertSee('Ganti Berkas SK');
    }

    public function test_pimpinan_cannot_open_archive_or_admin_employee_detail(): void
    {
        $this->actingAsRole('pimpinan');
        $employee = Employee::factory()->create();

        $this->get(route('dokumen'))->assertOk();
        $this->get(route('pegawai.show', $employee->id))
            ->assertRedirect(route('rbac.pegawai.show', $employee->id));
    }

    public function test_pimpinan_receives_archive_link_when_document_permission_is_active(): void
    {
        $this->actingAsRole('pimpinan');

        $this->get(route('pimpinan.dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('dokumen').'"', false)
            ->assertSee('Dokumen &amp; SK', false);
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

    public function test_profile_document_mutations_invalidate_central_archive_cache_after_success(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();

        $content = $this->get(route('rbac.pegawai.show', $employee->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("key && key.startsWith('dokumen_')", $content);
        $this->assertMatchesRegularExpression(
            '/async submitUploadBerkas\(\).*?if \(res\.ok\).*?this\.clearDocumentArchiveCache\(\);/s',
            $content,
        );
        $this->assertMatchesRegularExpression(
            '/async submitUpdateBerkas\(\).*?if \(res\.ok\).*?this\.clearDocumentArchiveCache\(\);/s',
            $content,
        );
        $this->assertMatchesRegularExpression(
            '/async submitDeleteBerkas\(\).*?if \(res\.ok\).*?this\.clearDocumentArchiveCache\(\);/s',
            $content,
        );
    }

    public function test_archive_revalidates_cached_rows_when_page_is_initialized(): void
    {
        $this->actingAsRole('admin_kepegawaian');

        $content = $this->get(route('dokumen'))
            ->assertOk()
            ->getContent();

        $initStart = strpos($content, 'init() {');
        $watchStart = strpos($content, '_watchFilters() {', $initStart === false ? 0 : $initStart);

        $this->assertNotFalse($initStart);
        $this->assertNotFalse($watchStart);

        $initScript = substr($content, $initStart, $watchStart - $initStart);

        $this->assertStringContainsString('this.documentsRows = cached.rows;', $initScript);
        $this->assertStringContainsString('this.fetchPage(this.meta.current_page, true);', $initScript);
        $this->assertStringContainsString("params.set('refresh', '1');", $content);
    }
}
