<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Employee;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefUnitKerja;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);

        Storage::fake('public');
    }

    public function test_rank_history_creation_syncs_to_documents_table(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $golongan = RefGolongan::where('kode', 'III/b')->firstOrFail();

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])
            ->postJson("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan", [
                'golongan_id' => $golongan->id,
                'tmt_pangkat' => '2026-02-01',
                'no_sk' => 'SK-RANK-SYNC',
                'tanggal_sk' => '2026-02-10',
                'file_sk' => UploadedFile::fake()->create('sk-rank.pdf', 100),
            ], ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertCreated();

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Kenaikan Pangkat III/b',
            'nomor_dokumen' => 'SK-RANK-SYNC',
        ]);
    }

    public function test_position_history_creation_syncs_to_documents_table(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $jenisJabatan = RefJenisJabatan::where('nama', 'Struktural')->firstOrFail();
        $unitKerja = RefUnitKerja::firstOrFail();

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])
            ->postJson("/api/v1/pegawai/{$employee->id}/riwayat-jabatan", [
                'nama_jabatan' => 'Kepala Subbagian Umum',
                'jenis_jabatan_id' => $jenisJabatan->id,
                'unit_kerja_id' => $unitKerja->id,
                'tmt_jabatan' => '2026-03-01',
                'no_sk' => 'SK-POS-SYNC',
                'tanggal_sk' => '2026-03-10',
                'file_sk' => UploadedFile::fake()->create('sk-pos.pdf', 100),
            ], ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertCreated();

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_jabatan',
            'nama_dokumen' => 'SK Kenaikan Jabatan Kepala Subbagian Umum',
            'nomor_dokumen' => 'SK-POS-SYNC',
        ]);
    }

    public function test_kgb_history_creation_syncs_to_documents_table(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])
            ->postJson("/api/v1/pegawai/{$employee->id}/riwayat-kgb", [
                'tmt_kgb' => '2026-04-01',
                'gaji_pokok' => 4500000,
                'no_sk' => 'SK-KGB-SYNC',
                'tanggal_sk' => '2026-04-10',
                'file_sk' => UploadedFile::fake()->create('sk-kgb.pdf', 100),
            ], ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertCreated();

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_kgb',
            'nama_dokumen' => 'SK KGB TMT 01-04-2026',
            'nomor_dokumen' => 'SK-KGB-SYNC',
        ]);
    }

    public function test_admin_can_access_document_index_page(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Pangkat Tester',
            'nomor_dokumen' => 'SK-TEST-INDEX',
            'tanggal_dokumen' => '2026-01-01',
            'file_path' => 'employees/documents/test.pdf',
        ]);

        $this->actingAs($user);
        $response = $this->get('/dashboard/dokumen');

        $response->assertOk();
        $response->assertViewHas('pegawaiList');
        $response->assertViewHas('documents');
    }

    public function test_admin_can_upload_document_via_controller(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->post('/dashboard/dokumen/upload', [
            'nama_dokumen' => 'Ijazah Master Tester',
            'nomor_dokumen' => 'IJZ-M-TEST',
            'tanggal_terbit' => '2026-01-01',
            'kategori_dokumen' => 'ijazah',
            'pegawai_id' => $employee->id,
            'berkas' => UploadedFile::fake()->create('ijazah.pdf', 100),
        ]);

        $response->assertRedirect('/dashboard/dokumen');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ijazah',
            'nama_dokumen' => 'Ijazah Master Tester',
            'nomor_dokumen' => 'IJZ-M-TEST',
        ]);
    }

    public function test_admin_can_download_document(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $file = UploadedFile::fake()->create('download-test.pdf', 100);
        $filePath = $file->store('employees/documents', 'public');

        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ijazah',
            'nama_dokumen' => 'Ijazah Download Tester',
            'nomor_dokumen' => 'IJZ-DL-TEST',
            'tanggal_dokumen' => '2026-01-01',
            'file_path' => $filePath,
        ]);

        $this->actingAs($user);
        $response = $this->get("/dashboard/dokumen/{$document->id}/download");

        $response->assertOk();
        
        $expectedFilename = \Illuminate\Support\Str::slug($employee->nama_lengkap ?? 'pegawai') . '-' . \Illuminate\Support\Str::slug($document->nama_dokumen) . '.pdf';
        $response->assertDownload($expectedFilename);
    }
}
