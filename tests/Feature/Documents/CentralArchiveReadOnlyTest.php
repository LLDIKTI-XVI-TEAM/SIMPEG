<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Arsip dokumen terpusat bersifat baca-saja: seluruh mutasi web
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

    /**
     * K-privasi (lapisan terpisah dari RBAC aksi): arsip memuat dokumen sensitif
     * lintas pegawai (mis. ktp_kk) — permission employees.read saja tidak cukup;
     * baca/unduh wajib lolos DocumentAuthorization::canViewArchive.
     * Pimpinan default (dengan dokumen_sk.read) boleh akses; tanpa dokumen_sk.read tetap dilarang.
     */
    public function test_pimpinan_dengan_employees_read_tetap_dilarang_mengakses_arsip(): void
    {
        $this->actingAsRole('pimpinan');
        // Cabut dokumen_sk.read agar tersisa employees.read saja.
        Role::where('name', 'pimpinan')->firstOrFail()
            ->permissions()->detach(Permission::where('name', 'dokumen_sk.read')->firstOrFail()->id);
        $document = $this->createBerkas();

        $this->get(route('dokumen'))->assertForbidden();
        $this->get(route('dokumen.show', $document->id))->assertForbidden();
        $this->get(route('dokumen.download', $document->id))->assertForbidden();
    }

    public function test_pimpinan_dengan_dokumen_read_boleh_mengakses_arsip(): void
    {
        $this->actingAsRole('pimpinan');
        $document = $this->createBerkas();

        $this->get(route('dokumen'))->assertOk();
        $this->get(route('dokumen.show', $document->id))->assertOk();
        $this->get(route('dokumen.download', $document->id))->assertOk();
    }

    public function test_archive_search_matches_category_label_and_key(): void
    {
        $this->actingAsRole('admin_kepegawaian');

        $employee = Employee::factory()->create();
        $ktpDocument = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ktp_kk',
            'nama_dokumen' => 'Identitas Pegawai 001',
            'file_path' => $employee->id.'/ktp_kk/identitas.pdf',
        ]);
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ijazah',
            'nama_dokumen' => 'Ijazah S1 Teknik',
            'file_path' => $employee->id.'/ijazah/s1.pdf',
        ]);

        // Pencarian label kategori harus menemukan dokumennya meski label tidak
        // tersimpan apa adanya di kolom jenis_dokumen.
        $this->getJson('/api/v1/dokumen?search='.rawurlencode('KTP & KK').'&per_page=5')
            ->assertOk()
            ->assertJsonCount(1, 'documents.data')
            ->assertJsonPath('documents.total', 1)
            ->assertJsonPath('documents.data.0.id', $ktpDocument->id);

        // Kunci kategori (nilai kolom) juga harus bisa dicari langsung.
        $this->getJson('/api/v1/dokumen?search=ktp_kk&per_page=5')
            ->assertOk()
            ->assertJsonCount(1, 'documents.data')
            ->assertJsonPath('documents.data.0.id', $ktpDocument->id);

        // Pencarian parsial label lain tetap bekerja.
        $this->getJson('/api/v1/dokumen?search=Ijazah&per_page=5')
            ->assertOk()
            ->assertJsonCount(1, 'documents.data')
            ->assertJsonPath('documents.data.0.nama_pegawai', $employee->nama_lengkap);
    }

    public function test_archive_searches_across_employees_by_name_and_nip_with_correct_pagination(): void
    {
        $this->actingAsRole('admin_kepegawaian');

        $target = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Sasaran Arsip',
            'nip' => '198801012010011001',
        ]);
        $other = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Pembanding',
            'nip' => '199901012020012002',
        ]);

        $targetDocument = Document::create([
            'employee_id' => $target->id,
            'jenis_dokumen' => 'ktp_kk',
            'nama_dokumen' => 'Identitas Sasaran',
            'file_path' => $target->id.'/ktp_kk/target.pdf',
        ]);
        Document::create([
            'employee_id' => $other->id,
            'jenis_dokumen' => 'ijazah',
            'nama_dokumen' => 'Ijazah Pembanding',
            'file_path' => $other->id.'/ijazah/pembanding.pdf',
        ]);

        $this->getJson('/api/v1/dokumen?search=Sasaran&per_page=5')
            ->assertOk()
            ->assertJsonCount(1, 'documents.data')
            ->assertJsonPath('documents.total', 1)
            ->assertJsonPath('documents.data.0.id', $targetDocument->id)
            ->assertJsonPath('documents.data.0.nama_pegawai', 'Pegawai Sasaran Arsip');

        $this->getJson('/api/v1/dokumen?search=198801012010011001&per_page=5')
            ->assertOk()
            ->assertJsonCount(1, 'documents.data')
            ->assertJsonPath('documents.total', 1)
            ->assertJsonPath('documents.data.0.id', $targetDocument->id);
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
