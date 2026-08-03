<?php

namespace Tests\Feature;

use App\Actions\Documents\DeleteDocumentAction;
use App\Actions\Documents\UpdateDocumentAction;
use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
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
use Illuminate\Validation\ValidationException;
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

    public function test_edit_document_without_replacement_preserves_existing_file(): void
    {
        $employee = Employee::factory()->create();
        $filePath = 'employees/documents/dokumen-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'file lama');
        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Lama',
            'nomor_dokumen' => 'DOC-LAMA',
            'file_path' => $filePath,
            'keterangan' => 'Keterangan lama',
        ]);

        $updated = app(UpdateDocumentAction::class)->execute($document, [
            'kategori_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Diperbarui',
            'nomor_dokumen' => 'DOC-BARU',
            'tanggal_terbit' => '2026-07-16',
            'deskripsi' => 'Keterangan baru',
        ]);

        $this->assertSame($filePath, $updated->file_path);
        Storage::disk(Document::STORAGE_DISK)->assertExists($filePath);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'nama_dokumen' => 'Dokumen Diperbarui',
            'nomor_dokumen' => 'DOC-BARU',
            'keterangan' => 'Keterangan baru',
            'file_path' => $filePath,
        ]);
    }

    public function test_edit_document_with_replacement_deletes_old_file_after_database_update(): void
    {
        $employee = Employee::factory()->create();
        $oldFilePath = 'employees/documents/dokumen-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($oldFilePath, 'file lama');
        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Lama',
            'nomor_dokumen' => 'DOC-LAMA',
            'file_path' => $oldFilePath,
        ]);

        $updated = app(UpdateDocumentAction::class)->execute(
            $document,
            [
                'kategori_dokumen' => 'ijazah',
                'nama_dokumen' => 'Dokumen Baru',
                'nomor_dokumen' => 'DOC-BARU',
                'tanggal_terbit' => '2026-07-16',
                'deskripsi' => 'File pengganti',
            ],
            UploadedFile::fake()->create('dokumen-baru.pdf', 100, 'application/pdf'),
        );

        $this->assertNotSame($oldFilePath, $updated->file_path);
        $this->assertStringStartsWith($employee->id.'/ijazah/', $updated->file_path);
        Storage::disk(Document::STORAGE_DISK)->assertMissing($oldFilePath);
        Storage::disk(Document::STORAGE_DISK)->assertExists($updated->file_path);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'jenis_dokumen' => 'ijazah',
            'nama_dokumen' => 'Dokumen Baru',
            'nomor_dokumen' => 'DOC-BARU',
            'keterangan' => 'File pengganti',
            'file_path' => $updated->file_path,
        ]);
    }

    public function test_edit_document_with_replacement_keeps_old_file_when_another_document_still_references_it(): void
    {
        $employee = Employee::factory()->create();
        $oldFilePath = 'employees/documents/dokumen-dipakai-bersama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($oldFilePath, 'file lama');
        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Utama',
            'file_path' => $oldFilePath,
        ]);
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Referensi Bersama',
            'file_path' => $oldFilePath,
        ]);

        $updated = app(UpdateDocumentAction::class)->execute(
            $document,
            [
                'kategori_dokumen' => 'lainnya',
                'nama_dokumen' => 'Dokumen Utama Diperbarui',
                'nomor_dokumen' => null,
                'tanggal_terbit' => null,
                'deskripsi' => null,
            ],
            UploadedFile::fake()->create('dokumen-pengganti.pdf', 100, 'application/pdf'),
        );

        Storage::disk(Document::STORAGE_DISK)->assertExists($oldFilePath);
        Storage::disk(Document::STORAGE_DISK)->assertExists($updated->file_path);
    }

    public function test_document_list_api_rechecks_storage_file_status_on_every_request(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $filePath = 'employees/documents/status-file.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'dokumen tersedia');

        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Status File',
            'file_path' => $filePath,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/dokumen?refresh=1');
        $response
            ->assertOk()
            ->assertJsonPath('documents.data.0.status_dokumen', 'tersedia')
            ->assertJsonPath('documents.data.0.status_label', 'File tersedia');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        Storage::disk(Document::STORAGE_DISK)->delete($filePath);

        $this->actingAs($user)
            ->getJson('/api/v1/dokumen?refresh=1')
            ->assertOk()
            ->assertJsonPath('documents.data.0.status_dokumen', 'file_tidak_ditemukan')
            ->assertJsonPath('documents.data.0.status_label', 'File tidak ditemukan');
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
        $this->assertMatchesRegularExpression('/^'.preg_quote($employee->id, '/').'\/ijazah\/'.preg_quote($employee->id, '/').'_ijazah_[0-9a-f-]{36}\.pdf$/', $document->file_path);
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
        $this->assertSame([], Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_admin_cannot_upload_document_larger_than_size_limit_without_creating_file(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->from('/dashboard/dokumen')->post('/dashboard/dokumen/upload', [
            'nama_dokumen' => 'Dokumen Terlalu Besar',
            'kategori_dokumen' => 'lainnya',
            'pegawai_id' => $employee->id,
            'berkas' => UploadedFile::fake()->create('terlalu-besar.pdf', 10241, 'application/pdf'),
        ]);

        $response->assertRedirect('/dashboard/dokumen');
        $response->assertSessionHasErrors('berkas');
        $this->assertDatabaseMissing('documents', [
            'employee_id' => $employee->id,
            'nama_dokumen' => 'Dokumen Terlalu Besar',
        ]);
        $this->assertSame([], Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_admin_can_download_document(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $filePath = 'employees/documents/download-test.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'dummy pdf content');

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
        } catch (ValidationException) {
            $this->assertDatabaseHas('documents', ['id' => $document->id]);
            Storage::disk(Document::STORAGE_DISK)->assertExists($filePath);
        }
    }

    public function test_document_linked_to_discipline_is_blocked_without_force_bypass(): void
    {
        $employee = Employee::factory()->create();
        $filePath = 'discipline/sk/hukuman-disiplin-terhubung.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'SK hukuman disiplin');
        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_hukuman_disiplin',
            'nama_dokumen' => 'SK Hukuman Disiplin',
            'nomor_dokumen' => 'SK-DIS-FORCE',
            'file_path' => $filePath,
        ]);
        $discipline = DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'Teguran tertulis.',
            'tanggal_mulai' => '2026-07-01',
            'tanggal_berakhir' => '2026-07-31',
            'no_sk' => 'SK-DIS-FORCE',
            'tanggal_sk' => '2026-06-30',
            'file_sk' => $filePath,
            'is_active' => true,
        ]);

        $action = app(DeleteDocumentAction::class);
        $impact = $action->checkImpact($document);
        $deleteBlocked = false;

        try {
            $action->execute($document);
        } catch (ValidationException) {
            $deleteBlocked = true;
        }

        $this->assertSame([
            'parameter_execute' => 1,
            'impact_diblokir' => true,
            'kategori_dampak_ada' => true,
            'delete_ditolak' => true,
            'dokumen_tetap_ada' => true,
            'riwayat_tetap_ada' => true,
            'file_tetap_ada' => true,
        ], [
            'parameter_execute' => (new \ReflectionMethod($action, 'execute'))->getNumberOfParameters(),
            'impact_diblokir' => $impact['has_blocked'],
            'kategori_dampak_ada' => array_key_exists('Hukuman Disiplin', $impact['blocked_impacts']),
            'delete_ditolak' => $deleteBlocked,
            'dokumen_tetap_ada' => Document::whereKey($document->id)->exists(),
            'riwayat_tetap_ada' => DisciplineRecord::whereKey($discipline->id)->exists(),
            'file_tetap_ada' => Storage::disk(Document::STORAGE_DISK)->exists($filePath),
        ]);
    }

    public function test_legacy_sk_mutasi_is_blocked_when_it_still_supports_employee_status(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Mutasi']);
        $filePath = 'berkas/'.$employee->id.'/sk-mutasi-lama.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($filePath, 'SK mutasi');

        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_nama' => 'Mutasi',
            'tanggal_efektif' => now(),
            'file_sk' => $filePath,
        ]);

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
