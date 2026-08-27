<?php

namespace Tests\Feature;

use App\Actions\Documents\DeleteDocumentAction;
use App\Actions\Documents\UpdateDocumentAction;
use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\LeaveRequest;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisCuti;
use App\Models\RefJenisJabatan;
use App\Models\RefUnitKerja;
use App\Models\StorageRecoveryTask;
use App\Models\User;
use App\Services\StorageRecoveryService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\SkRequirementSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\MockInterface;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class EmployeeDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(SkRequirementSeeder::class);
        $this->seed(RbacSeeder::class);
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
        $response->assertViewHas('categoryLabels');
        $response->assertViewMissing('pegawaiList');
    }

    public function test_admin_can_upload_additional_document_from_employee_profile(): void
    {
        Storage::fake('employee_documents');
        Storage::fake('public');
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $file = UploadedFile::fake()->create('ijazah.pdf', 100, 'application/pdf');

        $response = $this->postJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
            'nama_dokumen' => 'Ijazah Master Tester',
            'nomor_dokumen' => 'IJZ-M-TEST',
            'tanggal_terbit' => '2026-01-01',
            'kategori_dokumen' => 'ijazah',
            'berkas' => $file,
        ]);

        $response->assertCreated()
            ->assertJsonPath('document.jenis_dokumen', 'ijazah');

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'ijazah',
            'nama_dokumen' => 'Ijazah Master Tester',
            'nomor_dokumen' => 'IJZ-M-TEST',
        ]);

        $document = Document::where('employee_id', $employee->id)->where('jenis_dokumen', 'ijazah')->firstOrFail();
        $this->assertMatchesRegularExpression('/^'.preg_quote($employee->id, '/').'\/ijazah\/'.preg_quote($employee->id, '/').'_ijazah_[0-9a-f-]{36}\.pdf$/', $document->file_path);
        Storage::disk('employee_documents')->assertExists($document->file_path);
        Storage::disk('public')->assertMissing($document->file_path);
    }

    public function test_command_memindahkan_dokumen_legacy_publik_ke_storage_privat_secara_aman(): void
    {
        Storage::fake('employee_documents');
        Storage::fake('public');
        $employee = Employee::factory()->create();
        $path = 'pegawai/'.$employee->id.'/legacy.pdf';
        Storage::disk('public')->put($path, 'isi legacy');
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Legacy',
            'file_path' => $path,
        ]);

        $this->artisan('documents:migrate-to-private-storage')
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();
        Storage::disk('public')->assertExists($path);
        Storage::disk('employee_documents')->assertMissing($path);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->assertSuccessful();
        Storage::disk('employee_documents')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame('isi legacy', Storage::disk('employee_documents')->get($path));
    }

    public function test_command_memigrasikan_lampiran_cuti_legacy_ke_path_privat_kanonis_secara_idempoten(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $employee = Employee::factory()->create();
        $legacyPath = 'cuti/surat-dokter.pdf';
        $pdf = $this->validPdf('lampiran cuti legacy');
        Storage::disk('public')->put($legacyPath, $pdf);
        $leave = $this->createLeaveWithAttachment($employee, $legacyPath);

        $this->artisan('documents:migrate-to-private-storage')
            ->expectsOutputToContain('siap=1')
            ->assertSuccessful();
        $this->assertSame($legacyPath, $leave->fresh()->lampiran_path);
        Storage::disk('public')->assertExists($legacyPath);
        $this->assertSame([], Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->allFiles('cuti/lampiran'));

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->assertSuccessful();
        $migratedPath = $leave->fresh()->lampiran_path;
        $this->assertNotNull($migratedPath);
        $this->assertMatchesRegularExpression(
            '#^cuti/lampiran/'.preg_quote($employee->id, '#').'/[0-9a-f-]{36}\.pdf$#',
            $migratedPath,
        );
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->assertExists($migratedPath);
        Storage::disk('public')->assertMissing($legacyPath);
        $this->assertSame(
            $pdf,
            Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->get($migratedPath),
        );

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('sudah_privat=1')
            ->assertSuccessful();
        $this->assertSame($migratedPath, $leave->fresh()->lampiran_path);
        $this->assertSame([$migratedPath], Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->allFiles('cuti/lampiran'));
    }

    public function test_command_mengelompokkan_banyak_referensi_lampiran_bersama_per_pegawai_tanpa_menduplikasi_file(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $employees = [Employee::factory()->create(), Employee::factory()->create()];
        $legacyPath = 'cuti/dipakai-bersama.pdf';
        Storage::disk('public')->put($legacyPath, $this->validPdf('satu file untuk banyak referensi'));
        $leaveType = RefJenisCuti::firstOrCreate(
            ['code' => 'sakit_migrasi_lampiran_bersama'],
            ['nama' => 'Cuti Sakit Migrasi Bersama', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => false],
        );
        $now = now();
        $rows = [];
        for ($index = 0; $index < 60; $index++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'employee_id' => $employees[$index % 2]->id,
                'jenis_cuti_id' => $leaveType->id,
                'tanggal_mulai' => '2026-08-03',
                'tanggal_selesai' => '2026-08-03',
                'jumlah_hari_kerja' => 1,
                'alasan' => 'Migrasi referensi lampiran bersama.',
                'lampiran_path' => $legacyPath,
                'status' => 'menunggu_approval',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('leave_requests')->insert($rows);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->assertSuccessful();

        $pathsByEmployee = LeaveRequest::query()
            ->whereIn('employee_id', collect($employees)->pluck('id'))
            ->get(['employee_id', 'lampiran_path'])
            ->groupBy('employee_id');
        foreach ($employees as $employee) {
            $paths = $pathsByEmployee[$employee->id]->pluck('lampiran_path')->unique()->values();
            $this->assertCount(1, $paths);
            $this->assertMatchesRegularExpression(
                '#^cuti/lampiran/'.preg_quote($employee->id, '#').'/[0-9a-f-]{36}\.pdf$#',
                (string) $paths->sole(),
            );
        }
        $this->assertCount(2, Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->allFiles('cuti/lampiran'));
        Storage::disk('public')->assertMissing($legacyPath);
    }

    public function test_command_lampiran_cuti_gagal_tertutup_saat_target_privat_berisi_file_berbeda(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $employee = Employee::factory()->create();
        $legacyPath = 'cuti/konflik.pdf';
        $targetUuid = '00000000-0000-4000-8000-000000000901';
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/'.$targetUuid.'.pdf';
        $publicPdf = $this->validPdf('isi publik');
        $privatePdf = $this->validPdf('isi privat berbeda');
        Storage::disk('public')->put($legacyPath, $publicPdf);
        Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->put($targetPath, $privatePdf);
        $leave = $this->createLeaveWithAttachment($employee, $legacyPath);
        Str::createUuidsUsingSequence([Uuid::fromString($targetUuid)]);

        try {
            $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
                ->expectsOutputToContain('konflik')
                ->assertFailed();
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertSame($legacyPath, $leave->fresh()->lampiran_path);
        $this->assertSame($publicPdf, Storage::disk('public')->get($legacyPath));
        $this->assertSame(
            $privatePdf,
            Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->get($targetPath),
        );
    }

    public function test_command_lampiran_cuti_gagal_tertutup_saat_file_referensi_hilang(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $employee = Employee::factory()->create();
        $legacyPath = 'cuti/tidak-ada.pdf';
        $leave = $this->createLeaveWithAttachment($employee, $legacyPath);

        $this->artisan('documents:migrate-to-private-storage')
            ->expectsOutputToContain('hilang')
            ->assertFailed();
        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('hilang')
            ->assertFailed();

        $this->assertSame($legacyPath, $leave->fresh()->lampiran_path);
        $this->assertSame([], Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->allFiles('cuti/lampiran'));
    }

    public function test_command_lampiran_cuti_menolak_path_lintas_namespace_tanpa_mengubah_db_atau_file_publik(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $employee = Employee::factory()->create();
        $crossNamespacePath = 'exports/laporan-kepegawaian.pdf';
        Storage::disk('public')->put($crossNamespacePath, 'file domain lain yang tidak boleh disentuh');
        $leave = $this->createLeaveWithAttachment($employee, $crossNamespacePath);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('path lampiran cuti legacy tidak aman')
            ->assertFailed();

        $this->assertSame($crossNamespacePath, $leave->fresh()->lampiran_path);
        $this->assertSame(
            'file domain lain yang tidak boleh disentuh',
            Storage::disk('public')->get($crossNamespacePath),
        );
        $this->assertSame([], Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->allFiles());
    }

    public function test_command_lampiran_cuti_menolak_pdf_dengan_mime_spoof_tanpa_memindahkan_source(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $employee = Employee::factory()->create();
        $legacyPath = 'cuti/spoof.pdf';
        Storage::disk('public')->put($legacyPath, "MZ\x90\x00binary executable");
        $leave = $this->createLeaveWithAttachment($employee, $legacyPath);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])->assertFailed();

        $this->assertSame($legacyPath, $leave->fresh()->lampiran_path);
        Storage::disk('public')->assertExists($legacyPath);
        $this->assertSame([], Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->allFiles());
    }

    public function test_command_lampiran_cuti_menolak_file_di_atas_batas_upload_tanpa_memindahkan_source(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $employee = Employee::factory()->create();
        $legacyPath = 'cuti/terlalu-besar.pdf';
        $this->writePublicPdfOfSize($legacyPath, (10 * 1024 * 1024) + 1);
        $leave = $this->createLeaveWithAttachment($employee, $legacyPath);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])->assertFailed();

        $this->assertSame($legacyPath, $leave->fresh()->lampiran_path);
        Storage::disk('public')->assertExists($legacyPath);
        $this->assertSame([], Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK)->allFiles());
    }

    public function test_command_lampiran_cuti_merollback_path_dan_mempertahankan_public_saat_salinan_privat_gagal(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $public = Storage::disk('public');
        $employeeDocuments = Storage::disk(Document::STORAGE_DISK);
        $legacyPath = 'cuti/gagal-salin.pdf';
        $targetUuid = '00000000-0000-4000-8000-000000000902';
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/';
        $pdf = $this->validPdf('isi yang wajib dipertahankan');
        $public->put($legacyPath, $pdf);
        $employee = Employee::factory()->create();
        $targetPath .= $employee->id.'/'.$targetUuid.'.pdf';
        $leave = $this->createLeaveWithAttachment($employee, $legacyPath);
        $failingPrivate = $this->filesystemMock();
        $this->expectation($failingPrivate, 'exists')->with($targetPath)->once()->andReturnFalse();
        $this->expectation($failingPrivate, 'writeStream')->withArgs(
            fn (string $path, mixed $stream): bool => $path === $targetPath && is_resource($stream),
        )->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('public')->andReturn($public);
        Storage::shouldReceive('disk')->with(Document::STORAGE_DISK)->andReturn($employeeDocuments);
        Storage::shouldReceive('disk')->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)->andReturn($failingPrivate);
        Str::createUuidsUsingSequence([Uuid::fromString($targetUuid)]);

        try {
            $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
                ->expectsOutputToContain('konflik')
                ->assertFailed();
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertSame($legacyPath, $leave->fresh()->lampiran_path);
        $this->assertTrue($public->exists($legacyPath));
        $this->assertSame($pdf, $public->get($legacyPath));
    }

    public function test_fault_precommit_meninggalkan_public_dan_db_utuh_dengan_target_cleanup_intent_yang_sudah_committed(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $public = Storage::disk('public');
        $employeeDocuments = Storage::disk(Document::STORAGE_DISK);
        $leaveDocuments = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $sourcePath = 'cuti/precommit-fault.pdf';
        $pdf = $this->validPdf('precommit fault');
        $public->put($sourcePath, $pdf);
        $employee = Employee::factory()->create();
        $leave = $this->createLeaveWithAttachment($employee, $sourcePath);
        $targetUuid = '00000000-0000-4000-8000-000000000938';
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/'.$targetUuid.'.pdf';
        $intentVisibleAtWrite = false;
        $failingPrivate = $this->filesystemMock();
        $this->expectation($failingPrivate, 'exists')->andReturnUsing(
            fn (string $path): bool => $leaveDocuments->exists($path),
        );
        $this->expectation($failingPrivate, 'readStream')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $path) => $leaveDocuments->readStream($path),
        );
        $this->expectation($failingPrivate, 'size')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $path): int => $leaveDocuments->size($path),
        );
        $this->expectation($failingPrivate, 'writeStream')->once()->withArgs(
            fn (string $path, mixed $stream): bool => $path === $targetPath && is_resource($stream),
        )->andReturnUsing(function (string $path, mixed $stream) use ($leaveDocuments, $targetPath, $pdf, &$intentVisibleAtWrite): bool {
            $intentVisibleAtWrite = StorageRecoveryTask::query()
                ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                ->where('category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT)
                ->where('disk', LeaveRequest::ATTACHMENT_STORAGE_DISK)
                ->where('path', $targetPath)
                ->where('sha256', hash('sha256', $pdf))
                ->exists();
            $leaveDocuments->writeStream($path, $stream);

            throw new \RuntimeException('Fault terkontrol setelah target write sebelum main commit.');
        });
        $this->expectation($failingPrivate, 'delete')->once()->with($targetPath)->andReturnUsing(
            fn (string $path): bool => $leaveDocuments->delete($path),
        );
        Storage::shouldReceive('disk')->with('public')->andReturn($public);
        Storage::shouldReceive('disk')->with(Document::STORAGE_DISK)->andReturn($employeeDocuments);
        Storage::shouldReceive('disk')->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)->andReturn($failingPrivate);
        $this->fakeUuidSequence($targetUuid);

        try {
            $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])->assertFailed();
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertTrue($intentVisibleAtWrite);
        $this->assertSame($sourcePath, $leave->fresh()->lampiran_path);
        $this->assertTrue($public->exists($sourcePath));
        $this->assertTrue($leaveDocuments->exists($targetPath));
        $intent = StorageRecoveryTask::query()->where('path', $targetPath)->sole();
        $this->assertSame(StorageRecoveryTask::STATUS_PREPARED, $intent->status);

        $this->artisan('storage:retry-recovery')->assertSuccessful();
        $this->assertFalse($leaveDocuments->exists($targetPath));
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $intent->fresh()->status);
    }

    public function test_fault_postcommit_meninggalkan_ref_privat_dan_source_delete_task_pending_lalu_retry_menghapus_public(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $public = Storage::disk('public');
        $employeeDocuments = Storage::disk(Document::STORAGE_DISK);
        $leaveDocuments = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $sourcePath = 'cuti/postcommit-fault.pdf';
        $pdf = $this->validPdf('postcommit fault');
        $public->put($sourcePath, $pdf);
        $employee = Employee::factory()->create();
        $leave = $this->createLeaveWithAttachment($employee, $sourcePath);
        $targetUuid = '00000000-0000-4000-8000-000000000943';
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/'.$targetUuid.'.pdf';
        $deleteAttempts = 0;
        $faultyPublic = $this->faultyPublicDeleteDisk($public, $sourcePath, $deleteAttempts, true);
        Storage::shouldReceive('disk')->with('public')->andReturn($faultyPublic);
        Storage::shouldReceive('disk')->with(Document::STORAGE_DISK)->andReturn($employeeDocuments);
        Storage::shouldReceive('disk')->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)->andReturn($leaveDocuments);
        $this->fakeUuidSequence($targetUuid);

        try {
            $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])->assertFailed();
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertSame($targetPath, $leave->fresh()->lampiran_path);
        $this->assertTrue($leaveDocuments->exists($targetPath));
        $this->assertTrue($public->exists($sourcePath));
        $sourceTask = StorageRecoveryTask::query()
            ->where('disk', 'public')
            ->where('path', $sourcePath)
            ->sole();
        $this->assertSame(StorageRecoveryTask::STATUS_PENDING, $sourceTask->status);
        $this->assertSame(hash('sha256', $pdf), $sourceTask->sha256);

        $this->artisan('storage:retry-recovery')->assertSuccessful();
        $this->assertFalse($public->exists($sourcePath));
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $sourceTask->fresh()->status);
    }

    public function test_retry_source_delete_berubah_hash_memindahkan_task_ke_manual_review_tanpa_delete(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $public = Storage::disk('public');
        $employeeDocuments = Storage::disk(Document::STORAGE_DISK);
        $leaveDocuments = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $sourcePath = 'cuti/source-berubah.pdf';
        $pdf = $this->validPdf('source awal');
        $changedPdf = $this->validPdf('source berubah');
        $public->put($sourcePath, $pdf);
        $employee = Employee::factory()->create();
        $leave = $this->createLeaveWithAttachment($employee, $sourcePath);
        $targetUuid = '00000000-0000-4000-8000-000000000947';
        $deleteAttempts = 0;
        $faultyPublic = $this->faultyPublicDeleteDisk($public, $sourcePath, $deleteAttempts, false);
        Storage::shouldReceive('disk')->with('public')->andReturn($faultyPublic);
        Storage::shouldReceive('disk')->with(Document::STORAGE_DISK)->andReturn($employeeDocuments);
        Storage::shouldReceive('disk')->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)->andReturn($leaveDocuments);
        $this->fakeUuidSequence($targetUuid);

        try {
            $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])->assertFailed();
        } finally {
            Str::createUuidsNormally();
        }

        $public->put($sourcePath, $changedPdf);
        $this->artisan('storage:retry-recovery')->assertFailed();

        $this->assertSame($targetUuid, pathinfo((string) $leave->fresh()->lampiran_path, PATHINFO_FILENAME));
        $this->assertSame($changedPdf, $public->get($sourcePath));
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'disk' => 'public',
            'path' => $sourcePath,
            'status' => StorageRecoveryTask::STATUS_MANUAL_REVIEW,
            'sha256' => hash('sha256', $pdf),
        ]);
    }

    public function test_retry_target_intent_berubah_hash_memindahkan_task_ke_manual_review_tanpa_delete(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $public = Storage::disk('public');
        $employeeDocuments = Storage::disk(Document::STORAGE_DISK);
        $leaveDocuments = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $sourcePath = 'cuti/target-berubah.pdf';
        $pdf = $this->validPdf('target awal');
        $changedPdf = $this->validPdf('target berubah');
        $public->put($sourcePath, $pdf);
        $employee = Employee::factory()->create();
        $leave = $this->createLeaveWithAttachment($employee, $sourcePath);
        $targetUuid = '00000000-0000-4000-8000-000000000951';
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/'.$targetUuid.'.pdf';
        $failingPrivate = $this->filesystemMock();
        $this->expectation($failingPrivate, 'exists')->andReturnUsing(
            fn (string $path): bool => $leaveDocuments->exists($path),
        );
        $this->expectation($failingPrivate, 'readStream')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $path) => $leaveDocuments->readStream($path),
        );
        $this->expectation($failingPrivate, 'size')->zeroOrMoreTimes()->andReturnUsing(
            fn (string $path): int => $leaveDocuments->size($path),
        );
        $this->expectation($failingPrivate, 'writeStream')->once()->andReturnUsing(
            function (string $path, mixed $stream) use ($leaveDocuments, $changedPdf): bool {
                $leaveDocuments->put($path, $changedPdf);

                throw new \RuntimeException('Fault terkontrol dengan target berbeda sebelum commit.');
            },
        );
        $this->expectation($failingPrivate, 'delete')->never();
        Storage::shouldReceive('disk')->with('public')->andReturn($public);
        Storage::shouldReceive('disk')->with(Document::STORAGE_DISK)->andReturn($employeeDocuments);
        Storage::shouldReceive('disk')->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)->andReturn($failingPrivate);
        $this->fakeUuidSequence($targetUuid);

        try {
            $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])->assertFailed();
        } finally {
            Str::createUuidsNormally();
        }

        $this->artisan('storage:retry-recovery')->assertFailed();
        $this->assertSame($sourcePath, $leave->fresh()->lampiran_path);
        $this->assertTrue($public->exists($sourcePath));
        $this->assertSame($changedPdf, $leaveDocuments->get($targetPath));
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'disk' => LeaveRequest::ATTACHMENT_STORAGE_DISK,
            'path' => $targetPath,
            'status' => StorageRecoveryTask::STATUS_MANUAL_REVIEW,
            'sha256' => hash('sha256', $pdf),
        ]);
    }

    public function test_canonical_public_duplicate_dihapus_setelah_commit_tanpa_tertahan_referensi_db_local(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $public = Storage::disk('public');
        $employeeDocuments = Storage::disk(Document::STORAGE_DISK);
        $leaveDocuments = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $employee = Employee::factory()->create();
        $canonicalPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/00000000-0000-4000-8000-000000000955.pdf';
        $pdf = $this->validPdf('duplikat publik canonical');
        $public->put($canonicalPath, $pdf);
        $leaveDocuments->put($canonicalPath, $pdf);
        $leave = $this->createLeaveWithAttachment($employee, $canonicalPath);
        $deleteAttempts = 0;
        $faultyPublic = $this->faultyPublicDeleteDisk($public, $canonicalPath, $deleteAttempts, true);
        Storage::shouldReceive('disk')->with('public')->andReturn($faultyPublic);
        Storage::shouldReceive('disk')->with(Document::STORAGE_DISK)->andReturn($employeeDocuments);
        Storage::shouldReceive('disk')->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)->andReturn($leaveDocuments);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])->assertFailed();

        $this->assertSame($canonicalPath, $leave->fresh()->lampiran_path);
        $this->assertSame($pdf, $leaveDocuments->get($canonicalPath));
        $this->assertTrue($public->exists($canonicalPath));
        $sourceTask = StorageRecoveryTask::query()
            ->where('category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY)
            ->where('disk', 'public')
            ->where('path', $canonicalPath)
            ->where('owner_id', $employee->id)
            ->sole();
        $this->assertSame(StorageRecoveryTask::STATUS_PENDING, $sourceTask->status);
        $this->assertSame(hash('sha256', $pdf), $sourceTask->sha256);

        $this->artisan('storage:retry-recovery')->assertSuccessful();
        $this->assertFalse($public->exists($canonicalPath));
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $sourceTask->fresh()->status);
    }

    public function test_delete_postcommit_yang_sudah_unlink_lalu_mengembalikan_false_diselesaikan_retry_secara_durable(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        Storage::fake('public');
        $public = Storage::disk('public');
        $employeeDocuments = Storage::disk(Document::STORAGE_DISK);
        $leaveDocuments = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $legacyPath = 'cuti/restore-failure.pdf';
        $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n";
        $public->put($legacyPath, $pdf);
        $employee = Employee::factory()->create();
        $leave = $this->createLeaveWithAttachment($employee, $legacyPath);
        $targetUuid = '00000000-0000-4000-8000-000000000936';
        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employee->id.'/'.$targetUuid.'.pdf';
        $failingPublic = $this->filesystemMock();
        $this->expectation($failingPublic, 'exists')->zeroOrMoreTimes()->with($legacyPath)->andReturnUsing(
            fn (): bool => $public->exists($legacyPath),
        );
        $this->expectation($failingPublic, 'size')->zeroOrMoreTimes()->with($legacyPath)->andReturn(strlen($pdf));
        $this->expectation($failingPublic, 'get')->zeroOrMoreTimes()->with($legacyPath)->andReturn($pdf);
        $this->expectation($failingPublic, 'readStream')->zeroOrMoreTimes()->with($legacyPath)->andReturnUsing(
            function () use ($pdf) {
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, $pdf);
                rewind($stream);

                return $stream;
            },
        );
        $this->expectation($failingPublic, 'delete')->once()->with($legacyPath)->andReturnUsing(
            function () use ($public, $legacyPath): bool {
                $public->delete($legacyPath);

                return false;
            },
        );
        $this->expectation($failingPublic, 'allFiles')->zeroOrMoreTimes()->andReturn([]);
        Storage::shouldReceive('disk')->with('public')->andReturn($failingPublic);
        Storage::shouldReceive('disk')->with(Document::STORAGE_DISK)->andReturn($employeeDocuments);
        Storage::shouldReceive('disk')->with(LeaveRequest::ATTACHMENT_STORAGE_DISK)->andReturn($leaveDocuments);
        $this->fakeUuidSequence($targetUuid);

        try {
            $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
                ->expectsOutputToContain('konflik')
                ->assertFailed();
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertSame($targetPath, $leave->fresh()->lampiran_path);
        $this->assertFalse($public->exists($legacyPath));
        $this->assertTrue($leaveDocuments->exists($targetPath));
        $sourceTask = StorageRecoveryTask::query()->where('disk', 'public')->where('path', $legacyPath)->sole();
        $this->assertSame(StorageRecoveryTask::STATUS_PENDING, $sourceTask->status);
        $this->assertSame(StorageRecoveryTask::OPERATION_DELETE, $sourceTask->operation);
        $this->assertDatabaseHas('storage_recovery_tasks', [
            'id' => $sourceTask->id,
            'category' => StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            'disk' => 'public',
            'path' => $legacyPath,
            'owner_id' => null,
            'source_disk' => 'public',
            'source_path' => $legacyPath,
            'sha256' => hash('sha256', $pdf),
        ]);

        $this->artisan('storage:retry-recovery')->assertSuccessful();
        $this->assertSame(StorageRecoveryTask::STATUS_COMPLETED, $sourceTask->fresh()->status);
    }

    public function test_command_tidak_menimpa_file_privat_yang_berbeda_dengan_legacy_publik(): void
    {
        Storage::fake('employee_documents');
        Storage::fake('public');
        $employee = Employee::factory()->create();
        $path = 'pegawai/'.$employee->id.'/konflik.pdf';
        Storage::disk('public')->put($path, 'isi publik');
        Storage::disk('employee_documents')->put($path, 'isi privat berbeda');
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Konflik',
            'file_path' => $path,
        ]);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('konflik')
            ->assertFailed();

        $this->assertSame('isi privat berbeda', Storage::disk('employee_documents')->get($path));
        Storage::disk('public')->assertExists($path);
    }

    public function test_command_gagal_bila_path_referensi_tidak_ada_di_public_maupun_privat(): void
    {
        Storage::fake('employee_documents');
        Storage::fake('public');
        $employee = Employee::factory()->create();
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Dokumen Hilang Migrasi',
            'file_path' => 'pegawai/hilang-migrasi.pdf',
        ]);

        $this->artisan('documents:migrate-to-private-storage')
            ->expectsOutputToContain('hilang')
            ->assertFailed();

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('hilang')
            ->assertFailed();
    }

    public function test_command_mendeteksi_dan_mengarantina_orphan_dokumen_legacy_publik_secara_aman(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake('public');
        $orphanPath = 'ranks/sk/orphan-dokumen.pdf';
        $quarantinePath = 'quarantine/orphaned-public/'.$orphanPath;
        $photoPath = 'employees/photos/foto-tetap-publik.jpg';
        $unrelatedPublicPath = 'exports/rekap-non-dokumen.xlsx';
        Storage::disk('public')->put($orphanPath, 'isi orphan');
        Storage::disk('public')->put($photoPath, 'foto publik');
        Storage::disk('public')->put($unrelatedPublicPath, 'bukan dokumen pegawai');

        $this->artisan('documents:migrate-to-private-storage')
            ->expectsOutputToContain('yatim=1')
            ->assertFailed();
        Storage::disk('public')->assertExists($orphanPath);
        Storage::disk(Document::STORAGE_DISK)->assertMissing($quarantinePath);

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('dikarantina=1')
            ->assertSuccessful();
        Storage::disk('public')->assertMissing($orphanPath);
        Storage::disk(Document::STORAGE_DISK)->assertExists($quarantinePath);
        $this->assertSame('isi orphan', Storage::disk(Document::STORAGE_DISK)->get($quarantinePath));
        Storage::disk('public')->assertExists($photoPath);
        Storage::disk('public')->assertExists($unrelatedPublicPath);

        $this->artisan('documents:migrate-to-private-storage')
            ->expectsOutputToContain('yatim=0')
            ->assertSuccessful();
    }

    public function test_command_tidak_menimpa_file_karantina_orphan_yang_berbeda(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        Storage::fake('public');
        $orphanPath = 'sk/orphan-konflik.pdf';
        $quarantinePath = 'quarantine/orphaned-public/'.$orphanPath;
        Storage::disk('public')->put($orphanPath, 'isi publik');
        Storage::disk(Document::STORAGE_DISK)->put($quarantinePath, 'isi karantina berbeda');

        $this->artisan('documents:migrate-to-private-storage', ['--execute' => true])
            ->expectsOutputToContain('konflik')
            ->assertFailed();

        $this->assertSame('isi publik', Storage::disk('public')->get($orphanPath));
        $this->assertSame('isi karantina berbeda', Storage::disk(Document::STORAGE_DISK)->get($quarantinePath));
    }

    public function test_admin_can_upload_document_without_optional_number_and_date(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
            'nama_dokumen' => 'Dokumen Tanpa Nomor',
            'kategori_dokumen' => 'lainnya',
            'berkas' => UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated();

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
        $filesBeforeRequest = Storage::disk(Document::STORAGE_DISK)->allFiles();

        $this->actingAs($user);
        $response = $this->postJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
            'nama_dokumen' => 'Script Berbahaya',
            'kategori_dokumen' => 'lainnya',
            'berkas' => UploadedFile::fake()->create('script.sh', 5, 'text/x-shellscript'),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('berkas');
        $this->assertDatabaseMissing('documents', [
            'employee_id' => $employee->id,
            'nama_dokumen' => 'Script Berbahaya',
        ]);
        $this->assertSame($filesBeforeRequest, Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_admin_cannot_upload_document_larger_than_size_limit_without_creating_file(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $filesBeforeRequest = Storage::disk(Document::STORAGE_DISK)->allFiles();

        $this->actingAs($user);
        $response = $this->postJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
            'nama_dokumen' => 'Dokumen Terlalu Besar',
            'kategori_dokumen' => 'lainnya',
            'berkas' => UploadedFile::fake()->create('terlalu-besar.pdf', 10241, 'application/pdf'),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('berkas');
        $this->assertDatabaseMissing('documents', [
            'employee_id' => $employee->id,
            'nama_dokumen' => 'Dokumen Terlalu Besar',
        ]);
        $this->assertSame($filesBeforeRequest, Storage::disk(Document::STORAGE_DISK)->allFiles());
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

    private function createLeaveWithAttachment(Employee $employee, string $path): LeaveRequest
    {
        $leaveType = RefJenisCuti::firstOrCreate(
            ['code' => 'sakit_migrasi_lampiran'],
            [
                'nama' => 'Cuti Sakit Migrasi Lampiran',
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ],
        );

        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-03',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Migrasi lampiran cuti legacy.',
            'lampiran_path' => $path,
            'status' => 'menunggu_approval',
        ]);
    }

    /** Menjaga UUID target migrasi deterministik tanpa kehabisan UUID untuk task recovery. */
    private function fakeUuidSequence(string $targetUuid): void
    {
        Str::createUuidsUsingSequence([
            Uuid::fromString($targetUuid),
            Uuid::fromString('00000000-0000-4000-8000-000000000960'),
            Uuid::fromString('00000000-0000-4000-8000-000000000961'),
            Uuid::fromString('00000000-0000-4000-8000-000000000962'),
            Uuid::fromString('00000000-0000-4000-8000-000000000963'),
        ]);
    }

    /**
     * Menyuntikkan fault sesudah commit melalui adapter filesystem, bukan hook produksi.
     */
    private function faultyPublicDeleteDisk(
        Filesystem $public,
        string $sourcePath,
        int &$deleteAttempts,
        bool $succeedOnRetry,
    ): Filesystem {
        $faultyPublic = $this->filesystemMock();
        $this->expectation($faultyPublic, 'exists')->zeroOrMoreTimes()->with($sourcePath)->andReturnUsing(
            fn (): bool => $public->exists($sourcePath),
        );
        $this->expectation($faultyPublic, 'size')->zeroOrMoreTimes()->with($sourcePath)->andReturnUsing(
            fn (): int => $public->size($sourcePath),
        );
        $this->expectation($faultyPublic, 'readStream')->zeroOrMoreTimes()->with($sourcePath)->andReturnUsing(
            fn () => $public->readStream($sourcePath),
        );
        $deleteExpectation = $this->expectation($faultyPublic, 'delete')->with($sourcePath);

        if ($succeedOnRetry) {
            $deleteExpectation->twice()->andReturnUsing(
                function () use ($public, $sourcePath, &$deleteAttempts): bool {
                    $deleteAttempts++;

                    if ($deleteAttempts === 1) {
                        throw new \RuntimeException('Fault terkontrol setelah commit sebelum source delete selesai.');
                    }

                    return $public->delete($sourcePath);
                },
            );
        } else {
            $deleteExpectation->once()->andReturnUsing(
                function () use (&$deleteAttempts): never {
                    $deleteAttempts++;

                    throw new \RuntimeException('Fault terkontrol setelah commit sebelum source delete selesai.');
                },
            );
        }

        $this->expectation($faultyPublic, 'allFiles')->zeroOrMoreTimes()->andReturnUsing(
            fn (): array => $public->allFiles(),
        );

        return $faultyPublic;
    }

    /** Membuat mock filesystem dengan kontrak adapter dan Mockery yang eksplisit. */
    private function filesystemMock(): Filesystem&MockInterface
    {
        $mock = \Mockery::mock(Filesystem::class);

        if (! $mock instanceof Filesystem) {
            throw new LogicException('Mock filesystem tidak memenuhi kontrak yang diminta.');
        }

        return $mock;
    }

    /** Mengubah hasil shouldReceive menjadi ekspektasi konkret yang dapat dikonfigurasi. */
    private function expectation(MockInterface $mock, string $method): Expectation|CompositeExpectation
    {
        $expectation = $mock->shouldReceive($method);

        if (! $expectation instanceof Expectation && ! $expectation instanceof CompositeExpectation) {
            throw new LogicException('Mockery tidak mengembalikan ekspektasi metode.');
        }

        return $expectation;
    }

    private function validPdf(string $marker): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Title ({$marker}) >>\nendobj\n%%EOF\n";
    }

    /** Menulis fixture besar melalui stream agar test tidak membangun string 10 MB di memori. */
    private function writePublicPdfOfSize(string $path, int $size): void
    {
        $stream = tmpfile();
        $this->assertIsResource($stream);
        $header = "%PDF-1.4\n";
        fwrite($stream, $header);
        $remaining = $size - strlen($header);
        $chunk = str_repeat('A', 8192);

        while ($remaining > 0) {
            $length = min($remaining, strlen($chunk));
            fwrite($stream, substr($chunk, 0, $length));
            $remaining -= $length;
        }

        rewind($stream);
        $this->assertTrue(Storage::disk('public')->writeStream($path, $stream));
        fclose($stream);
    }
}
