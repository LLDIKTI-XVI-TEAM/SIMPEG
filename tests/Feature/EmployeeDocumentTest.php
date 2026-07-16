<?php

namespace Tests\Feature;

use App\Actions\Documents\DeleteDocumentAction;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\Employee;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefUnitKerja;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);

        Storage::fake(Document::STORAGE_DISK);
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
        $jabatan = RefJabatan::firstOrCreate(
            ['nama' => 'Kepala Subbagian Umum'],
            ['jenis_jabatan_id' => $jenisJabatan->id]
        );

        $this->actingAs($user);
        $response = $this->withSession(['_token' => 'test-token'])
            ->postJson("/api/v1/pegawai/{$employee->id}/riwayat-jabatan", [
                'jabatan_id' => $jabatan->id,
                'jenis_jabatan_id' => $jenisJabatan->id,
                'unit_kerja_id' => $unitKerja->id,
                'kelas_jabatan' => '9',
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
        $response->assertViewHas('categoryLabels');
    }

    public function test_admin_can_upload_document_via_controller(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $file = UploadedFile::fake()->create('ijazah.pdf', 100, 'application/pdf');

        $response = $this->post('/dashboard/dokumen/upload', [
            'nama_dokumen' => 'Ijazah Master Tester',
            'nomor_dokumen' => 'IJZ-M-TEST',
            'tanggal_terbit' => '2026-01-01',
            'kategori_dokumen' => 'ijazah',
            'pegawai_id' => $employee->id,
            'berkas' => $file,
        ]);

        $response->assertRedirect('/dashboard/dokumen');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ijazah',
            'nama_dokumen' => 'Ijazah Master Tester',
            'nomor_dokumen' => 'IJZ-M-TEST',
        ]);

        $document = Document::where('employee_id', $employee->id)->where('jenis_dokumen', 'ijazah')->firstOrFail();
        $this->assertMatchesRegularExpression('/^'.preg_quote($employee->id, '/').'\/ijazah\/'.preg_quote($employee->id, '/').'_ijazah_\d{14}\.pdf$/', $document->file_path);
        Storage::disk(Document::STORAGE_DISK)->assertExists($document->file_path);
    }

    public function test_admin_can_upload_document_without_optional_number_and_date(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->post('/dashboard/dokumen/upload', [
            'nama_dokumen' => 'Dokumen Tanpa Nomor',
            'kategori_dokumen' => 'lainnya',
            'pegawai_id' => $employee->id,
            'berkas' => UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect('/dashboard/dokumen');

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Tanpa Nomor',
            'nomor_dokumen' => null,
            'tanggal_dokumen' => null,
        ]);
    }

    public function test_admin_cannot_upload_document_with_disallowed_mime_type(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->from('/dashboard/dokumen')->post('/dashboard/dokumen/upload', [
            'nama_dokumen' => 'Script Berbahaya',
            'kategori_dokumen' => 'lainnya',
            'pegawai_id' => $employee->id,
            'berkas' => UploadedFile::fake()->create('script.sh', 5, 'text/x-shellscript'),
        ]);

        $response->assertRedirect('/dashboard/dokumen');
        $response->assertSessionHasErrors('berkas');
        $this->assertDatabaseMissing('documents', [
            'employee_id' => $employee->id,
            'nama_dokumen' => 'Script Berbahaya',
        ]);
    }

    public function test_admin_can_download_document(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $file = UploadedFile::fake()->create('download-test.pdf', 100);
        $filePath = $file->store('employees/documents', Document::STORAGE_DISK);

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

        $expectedFilename = Str::slug($employee->nama_lengkap ?? 'pegawai').'-'.Str::slug($document->nama_dokumen).'.pdf';
        $response->assertDownload($expectedFilename);
    }

    public function test_document_routes_reject_malformed_ids_before_controller_lookup(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);

        $this->get('/dashboard/dokumen/not-a-uuid')->assertNotFound();
        $this->get('/dashboard/dokumen/not-a-uuid/download')->assertNotFound();
        $this->get('/dashboard/dokumen/legacy')->assertRedirect(route('dokumen'));
    }

    public function test_document_used_by_appointment_is_detected_by_file_path_and_cannot_be_deleted(): void
    {
        $employee = Employee::factory()->create();
        $filePath = 'appointments/sk/pengangkatan-terhubung.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'SK pengangkatan');

        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pengangkatan',
            'nama_dokumen' => 'SK Pengangkatan',
            'file_path' => $filePath,
        ]);
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'file_sk' => $filePath,
        ]);

        $action = app(DeleteDocumentAction::class);
        $impact = $action->checkImpact($document);

        $this->assertTrue($impact['has_blocked']);
        $this->assertArrayHasKey('Pengangkatan', $impact['blocked_impacts']);

        try {
            $action->execute($document);
            $this->fail('Dokumen yang digunakan data pengangkatan tidak boleh dihapus.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseHas('documents', ['id' => $document->id]);
            Storage::disk(Document::STORAGE_DISK)->assertExists($filePath);
        }
    }

    public function test_legacy_sk_mutasi_is_blocked_when_it_still_supports_employee_status(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Mutasi']);
        $filePath = 'berkas/'.$employee->id.'/sk-mutasi-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'SK mutasi');

        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'SK Mutasi',
            'file_path' => $filePath,
        ]);

        $impact = app(DeleteDocumentAction::class)->checkImpact($document);

        $this->assertTrue($impact['has_blocked']);
        $this->assertArrayHasKey('Status Pegawai', $impact['blocked_impacts']);
    }

    public function test_unrelated_additional_document_can_be_deleted(): void
    {
        $employee = Employee::factory()->create();
        $filePath = 'berkas/'.$employee->id.'/ktp.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'KTP');

        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ktp_kk',
            'nama_dokumen' => 'KTP',
            'file_path' => $filePath,
        ]);

        $action = app(DeleteDocumentAction::class);
        $this->assertFalse($action->checkImpact($document)['has_blocked']);
        $action->execute($document);

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        Storage::disk(Document::STORAGE_DISK)->assertMissing($filePath);
    }
}
