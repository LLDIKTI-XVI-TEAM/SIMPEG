<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenjangPendidikan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Document::STORAGE_DISK);
        $this->seedRbac();
    }

    public function test_admin_can_post_append_only_sk_pangkat(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();
        $golongan = RefGolongan::create(['kode' => 'III/a', 'nama' => 'Penata Muda', 'urutan' => 1, 'is_active' => true]);

        $response = $this->post(
            "/api/v1/pegawai/{$employee->id}/berkas-sk",
            [
                'kategori_dokumen' => 'sk_pangkat',
                'golongan_id' => $golongan->id,
                'tmt_pangkat' => '2024-01-01',
                'no_sk' => 'SK/PANGKAT/2024',
                'tanggal_sk' => '2023-12-15',
                'file_sk' => UploadedFile::fake()->create('sk.pdf', 80, 'application/pdf'),
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertCreated()
            ->assertJsonPath('document.jenis_dokumen', 'sk_pangkat');

        $this->assertSame(1, $employee->rankHistories()->count());
        $this->assertTrue($employee->rankHistories()->first()?->is_latest);
    }

    public function test_non_sk_category_is_rejected_on_berkas_sk(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();

        $this->post(
            "/api/v1/pegawai/{$employee->id}/berkas-sk",
            [
                'kategori_dokumen' => 'ktp_kk',
                'no_sk' => 'KTP-1',
                'tanggal_sk' => '2024-01-01',
                'file_sk' => UploadedFile::fake()->create('ktp.pdf', 80, 'application/pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertUnprocessable();
    }

    public function test_super_admin_cannot_delete_sk_pangkat(): void
    {
        $this->actingAsRole('super_admin');
        $employee = Employee::factory()->create();
        $path = 'sk/pangkat.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($path, 'isi');

        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Pangkat',
            'file_path' => $path,
        ]);
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2024-01-01',
            'no_sk' => 'SK/1',
            'file_sk' => $path,
            'is_latest' => true,
        ]);

        $this->delete(
            "/api/v1/pegawai/{$employee->id}/dokumen/{$document->id}",
            [],
            ['Accept' => 'application/json'],
        )->assertUnprocessable();

        $this->assertTrue(Document::query()->whereKey($document->id)->exists());
    }

    public function test_super_admin_can_delete_berkas_lainnya(): void
    {
        $this->actingAsRole('super_admin');
        $employee = Employee::factory()->create();
        $path = 'berkas/lainnya.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($path, 'isi');

        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Surat Keterangan',
            'file_path' => $path,
        ]);

        $this->delete(
            "/api/v1/pegawai/{$employee->id}/dokumen/{$document->id}",
            [],
            ['Accept' => 'application/json'],
        )->assertOk();

        $this->assertFalse(Document::query()->whereKey($document->id)->exists());
    }

    public function test_admin_kepegawaian_cannot_delete_berkas_lainnya(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();
        $path = 'berkas/lainnya.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($path, 'isi');

        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Surat Keterangan',
            'file_path' => $path,
        ]);

        $this->delete(
            "/api/v1/pegawai/{$employee->id}/dokumen/{$document->id}",
            [],
            ['Accept' => 'application/json'],
        )->assertForbidden();
    }

    public function test_archive_store_is_forbidden(): void
    {
        $this->actingAsRole('super_admin');

        $this->post(route('dokumen.store'), [
            'nama_dokumen' => 'KTP',
            'kategori_dokumen' => 'ktp_kk',
            'pegawai_id' => Employee::factory()->create()->id,
            'berkas' => UploadedFile::fake()->create('ktp.pdf', 80, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_admin_can_edit_berkas_lainnya_metadata(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();
        $path = 'berkas/ktp-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($path, 'isi');

        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ktp_kk',
            'nama_dokumen' => 'KTP Lama',
            'nomor_dokumen' => 'KTP-1',
            'file_path' => $path,
        ]);

        $this->post("/api/v1/pegawai/{$employee->id}/dokumen/{$document->id}", [
            'nama_dokumen' => 'KTP Baru',
            'kategori_dokumen' => 'ktp_kk',
            'nomor_dokumen' => 'KTP-2',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('document.nama_dokumen', 'KTP Baru');

        $this->assertSame('KTP-2', $document->refresh()->nomor_dokumen);
    }

    public function test_admin_cannot_edit_sk_archive_metadata(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();
        $path = 'berkas/sk-pangkat.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($path, 'isi');

        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Pangkat',
            'file_path' => $path,
        ]);

        $this->post("/api/v1/pegawai/{$employee->id}/dokumen/{$document->id}", [
            'nama_dokumen' => 'SK Pangkat Diubah',
            'kategori_dokumen' => 'sk_pangkat',
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonValidationErrors('kategori_dokumen');

        $this->assertSame('SK Pangkat', $document->refresh()->nama_dokumen);
    }

    public function test_store_dokumen_ignores_payload_employee_and_uses_route_employee(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();

        $this->post("/api/v1/pegawai/{$employee->id}/dokumen", [
            'nama_dokumen' => 'Dokumen Milik Route',
            'kategori_dokumen' => 'lainnya',
            'pegawai_id' => $otherEmployee->id,
            'berkas' => UploadedFile::fake()->create('dok.pdf', 80, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'nama_dokumen' => 'Dokumen Milik Route',
        ]);
        $this->assertDatabaseMissing('documents', [
            'employee_id' => $otherEmployee->id,
            'nama_dokumen' => 'Dokumen Milik Route',
        ]);
    }

    public function test_update_cannot_reclassify_non_sk_to_sk_category(): void
    {
        // Direct API caller tidak boleh mengubah ktp_kk/ijazah/lainnya menjadi
        // kategori SK tanpa melewati jalur riwayat append-only & permission
        // employee_histories.create.
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();
        $path = 'berkas/ktp.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($path, 'isi');

        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ktp_kk',
            'nama_dokumen' => 'KTP',
            'file_path' => $path,
        ]);

        $this->post("/api/v1/pegawai/{$employee->id}/dokumen/{$document->id}", [
            'nama_dokumen' => 'SK Pangkat',
            'kategori_dokumen' => 'sk_pangkat',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kategori_dokumen');

        $this->assertSame('ktp_kk', $document->refresh()->jenis_dokumen);
        $this->assertSame('KTP', $document->refresh()->nama_dokumen);
    }

    public function test_replace_ijazah_syncs_education_history_file_ijazah(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();
        $oldPath = 'berkas/ijazah-lama.pdf';
        $newPath = 'berkas/ijazah-baru.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($oldPath, 'ijazah lama');
        Storage::disk(Document::STORAGE_DISK)->put($newPath, 'ijazah baru');

        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ijazah',
            'nama_dokumen' => 'Ijazah',
            'file_path' => $oldPath,
        ]);

        $jenjang = RefJenjangPendidikan::firstOrCreate(['nama' => 'S1'], ['urutan' => 1, 'is_active' => true]);
        $education = EducationHistory::create([
            'employee_id' => $employee->id,
            'jenjang_id' => $jenjang->id,
            'nama_institusi' => 'Universitas Test',
            'tahun_lulus' => 2019,
            'file_ijazah' => $oldPath,
        ]);

        $this->post("/api/v1/pegawai/{$employee->id}/dokumen/{$document->id}", [
            'nama_dokumen' => 'Ijazah Baru',
            'kategori_dokumen' => 'ijazah',
            'berkas' => UploadedFile::fake()->create('ijazah-baru.pdf', 80, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertOk();

        // EducationHistory.file_ijazah harus di-sync ke path baru, bukan dangling ke file lama.
        $this->assertSame($document->refresh()->file_path, $education->refresh()->file_ijazah);
        $this->assertNotSame($oldPath, $education->refresh()->file_ijazah);
        Storage::disk(Document::STORAGE_DISK)->assertExists((string) $education->refresh()->file_ijazah);
    }

    public function test_dokumen_store_rejects_sk_categories(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $employee = Employee::factory()->create();

        // Endpoint generic /dokumen hanya boleh melayani Berkas Lainnya; dokumen SK
        // wajib melalui jalur domain masing-masing (/berkas-sk / replace pengangkatan),
        // agar tidak ada jalur alternatif yang melewati append-only + izin riwayat.
        foreach (['sk_pangkat', 'sk_jabatan', 'sk_kgb', 'sk_pengangkatan'] as $sk) {
            $this->post(
                "/api/v1/pegawai/{$employee->id}/dokumen",
                [
                    'nama_dokumen' => 'SK',
                    'kategori_dokumen' => $sk,
                    'berkas' => UploadedFile::fake()->create('sk.pdf', 80, 'application/pdf'),
                ],
                ['Accept' => 'application/json'],
            )->assertUnprocessable();
        }

        // Berkas Lainnya tetap boleh diunggah melalui endpoint yang sama.
        $this->post(
            "/api/v1/pegawai/{$employee->id}/dokumen",
            [
                'nama_dokumen' => 'KTP',
                'kategori_dokumen' => 'ktp_kk',
                'berkas' => UploadedFile::fake()->create('ktp.pdf', 80, 'application/pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertCreated();
    }
}
