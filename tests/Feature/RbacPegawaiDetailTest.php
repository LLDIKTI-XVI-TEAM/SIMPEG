<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\Role;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RbacPegawaiDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    private function employeeWithReferences(array $overrides = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'nama_lengkap' => 'Pegawai RBAC',
            'nip' => '19800101200501100'.random_int(1, 9),
        ], $overrides));
    }

    public function test_super_admin_can_access_rbac_detail(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = $this->employeeWithReferences();

        $this->actingAs($user)->get(route('rbac.pegawai.show', $employee))->assertOk()->assertSee($employee->nama_lengkap);
    }

    public function test_admin_kepegawaian_can_access_rbac_detail(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->employeeWithReferences();

        $this->actingAs($user)->get(route('rbac.pegawai.show', $employee))->assertOk();
    }

    public function test_pimpinan_can_access_rbac_detail_masked(): void
    {
        $user = User::factory()->pimpinan()->create();
        $employee = $this->employeeWithReferences(['nik' => '3201010101010001', 'no_kk' => '3201010101010002']);
        // Ensure employee has families with NIK to test masking
        $employee->families()->create([
            'nama_anggota' => 'Anak Test',
            'nik' => '3201010101010003',
            'hubungan' => 'Anak',
            'tempat_lahir' => 'Bandung',
            'tanggal_lahir' => '2010-01-01',
            'jenis_kelamin' => 'L',
        ]);

        $response = $this->actingAs($user)->get(route('rbac.pegawai.show', $employee))->assertOk();
        // NIK should not be in raw payload for RBAC pimpinan (masked surface)
        $response->assertDontSee('3201010101010001', false);
        $response->assertDontSee('3201010101010003', false);
    }

    public function test_pegawai_can_access_own_rbac_detail_but_not_other(): void
    {
        $employeeSelf = $this->employeeWithReferences(['nama_lengkap' => 'Self']);
        $employeeOther = $this->employeeWithReferences(['nama_lengkap' => 'Other', 'nip' => '198001012005011099', 'email' => 'other@example.test']);
        $user = User::factory()->pegawai()->create(['employee_id' => $employeeSelf->id]);
        $user->refresh();
        // Grant employees.read to pegawai for canonical
        Role::where('name', 'pegawai')->firstOrFail()->permissions()->syncWithoutDetaching([Permission::where('name', 'employees.read')->firstOrFail()->id]);

        $this->actingAs($user)->get(route('rbac.pegawai.show', $employeeSelf))->assertOk();
        $this->actingAs($user)->get(route('rbac.pegawai.show', $employeeOther))->assertForbidden();
    }

    public function test_kepala_bagian_can_access_bawahan_via_rbac_but_not_other(): void
    {
        $kabag = $this->employeeWithReferences(['nama_lengkap' => 'Kabag', 'nip' => '198001012005011010']);
        $bawahan = $this->employeeWithReferences(['nama_lengkap' => 'Bawahan', 'nip' => '198001012005011011', 'email' => 'bawahan@example.test']);
        $other = $this->employeeWithReferences(['nama_lengkap' => 'Other2', 'nip' => '198001012005011012', 'email' => 'other2@example.test']);
        SupervisorAssignment::create([
            'employee_id' => $bawahan->id,
            'kepala_bagian_id' => $kabag->id,
            'supervisor_id' => $kabag->id,
            'tanggal_mulai' => now()->subDay()->toDateString(),
            'tanggal_berakhir' => null,
        ]);
        $user = User::factory()->kepalaBagian()->create(['employee_id' => $kabag->id]);
        Role::where('name', 'kepala_bagian')->firstOrFail()->permissions()->syncWithoutDetaching([Permission::where('name', 'employees.read')->firstOrFail()->id]);

        $this->actingAs($user)->get(route('rbac.pegawai.show', $bawahan))->assertOk();
        $this->actingAs($user)->get(route('rbac.pegawai.show', $other))->assertForbidden();
        $this->actingAs($user)->get(route('rbac.pegawai.show', $kabag))->assertForbidden(); // self not bawahan
    }

    public function test_rbac_detail_hides_histories_without_permission(): void
    {
        $user = User::factory()->pimpinan()->create();
        // Remove histories read
        Role::where('name', 'pimpinan')->firstOrFail()->permissions()->detach(Permission::where('name', 'employee_histories.read')->firstOrFail()->id);
        $user->refresh();
        $this->assertFalse($user->hasPermission('employee_histories.read'), 'Pimpinan should not have histories read after detach');
        $this->assertTrue($user->hasPermission('employees.read'), 'Pimpinan should still have employees.read');
        $employee = $this->employeeWithReferences();
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => RefGolongan::firstOrFail()->id,
            'tmt_pangkat' => '2020-01-01',
            'is_latest' => true,
        ]);

        $response = $this->actingAs($user)->get(route('rbac.pegawai.show', $employee))->assertOk();
        // Panel kepangkatan should be hidden when no histories permission (check by panel attribute)
        $response->assertDontSee('data-employee-detail-panel="kepangkatan"', false);
        $response->assertDontSee('id="rbac-tab-kepangkatan"', false);
    }

    public function test_rbac_detail_hides_documents_without_dokumen_read(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->pimpinan()->create();
        Role::where('name', 'pimpinan')->firstOrFail()->permissions()->detach(Permission::where('name', 'dokumen_sk.read')->firstOrFail()->id);
        $employee = $this->employeeWithReferences();
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Test',
            'file_path' => 'pegawai/test.pdf',
        ]);
        Storage::disk(Document::STORAGE_DISK)->put('pegawai/test.pdf', 'content');

        $response = $this->actingAs($user)->get(route('rbac.pegawai.show', $employee))->assertOk();
        $response->assertDontSee('Daftar Dokumen', false);
        // Direct download should 403
        $document = Document::where('employee_id', $employee->id)->firstOrFail();
        $this->actingAs($user)->get(route('rbac.pegawai.documents.download', ['employee' => $employee, 'document' => $document]))->assertForbidden();
    }

    public function test_create_history_without_dokumen_create_returns_warning_and_no_file(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        // Remove dokumen_sk.create
        Role::where('name', 'admin_kepegawaian')->firstOrFail()->permissions()->detach(Permission::where('name', 'dokumen_sk.create')->firstOrFail()->id);
        $user->refresh();
        $employee = $this->employeeWithReferences();
        $golongan = RefGolongan::firstOrFail();

        $file = UploadedFile::fake()->create('sk.pdf', 100, 'application/pdf');
        $response = $this->actingAs($user)->postJson("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan", [
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2024-01-01',
            'no_sk' => 'SK-TEST-001',
            'tanggal_sk' => '2024-01-01',
            'file_sk' => $file,
        ]);

        $response->assertStatus(201)->assertJsonPath('warning', 'Riwayat kepangkatan berhasil disimpan, tetapi berkas SK tidak diunggah karena Anda tidak memiliki permission dokumen_sk.create.');
        // History should exist but file_sk null and no document mirror
        $this->assertDatabaseHas('rank_histories', ['employee_id' => $employee->id, 'no_sk' => 'SK-TEST-001', 'file_sk' => null]);
        $this->assertDatabaseMissing('documents', ['employee_id' => $employee->id, 'jenis_dokumen' => 'sk_pangkat', 'nomor_dokumen' => 'SK-TEST-001']);
        $this->assertSame([], Storage::disk(Document::STORAGE_DISK)->allFiles());
    }

    public function test_create_history_with_dokumen_create_saves_file(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create(); // has dokumen_sk.create via seeder
        $employee = $this->employeeWithReferences();
        $golongan = RefGolongan::firstOrFail();
        $file = UploadedFile::fake()->create('sk.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user)->postJson("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan", [
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2024-01-02',
            'no_sk' => 'SK-TEST-002',
            'tanggal_sk' => '2024-01-02',
            'file_sk' => $file,
        ]);

        $response->assertStatus(201);
        $this->assertStringNotContainsString('warning', $response->getContent());
        $history = RankHistory::where('no_sk', 'SK-TEST-002')->firstOrFail();
        $this->assertNotNull($history->file_sk);
        $this->assertDatabaseHas('documents', ['employee_id' => $employee->id, 'jenis_dokumen' => 'sk_pangkat']);
    }

    public function test_dokumen_create_update_delete_lifecycle(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->employeeWithReferences();

        // Create
        $file = UploadedFile::fake()->create('ktp.pdf', 100, 'application/pdf');
        $create = $this->actingAs($user)->postJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
            'nama_dokumen' => 'KTP Test',
            'kategori_dokumen' => 'ktp_kk',
            'nomor_dokumen' => '123',
            'tanggal_terbit' => '2024-01-01',
            'keterangan' => 'test',
            'berkas' => $file,
        ])->assertStatus(201);
        $docId = $create->json('document.id');

        // Update requires dokumen_sk.update (has)
        $newFile = UploadedFile::fake()->create('ktp2.pdf', 100, 'application/pdf');
        $this->actingAs($user)->postJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya/{$docId}", [
            '_method' => 'PUT',
            'nama_dokumen' => 'KTP Update',
            'kategori_dokumen' => 'ktp_kk',
            'nomor_dokumen' => '123',
            'tanggal_terbit' => '2024-01-01',
            'keterangan' => 'update',
            'berkas' => $newFile,
        ])->assertOk();

        // Delete requires dokumen_sk.delete
        $this->actingAs($user)->deleteJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya/{$docId}")->assertOk();

        // Without delete permission, delete should 403
        $file2 = UploadedFile::fake()->create('kk.pdf', 100, 'application/pdf');
        $create2 = $this->actingAs($user)->postJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya", [
            'nama_dokumen' => 'KK Test',
            'kategori_dokumen' => 'ktp_kk',
            'berkas' => $file2,
        ])->assertStatus(201);
        $docId2 = $create2->json('document.id');
        Role::where('name', 'admin_kepegawaian')->firstOrFail()->permissions()->detach(Permission::where('name', 'dokumen_sk.delete')->firstOrFail()->id);
        $user->refresh();
        $this->actingAs($user)->deleteJson("/api/v1/pegawai/{$employee->id}/berkas-lainnya/{$docId2}")->assertForbidden();
    }

    public function test_rbac_download_requires_dokumen_read_and_scope(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $employee = $this->employeeWithReferences();
        $history = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => RefGolongan::firstOrFail()->id,
            'tmt_pangkat' => '2020-01-01',
            'no_sk' => 'SK-DOWNLOAD',
            'tanggal_sk' => '2020-01-01',
            'file_sk' => 'sk/download.pdf',
            'is_latest' => true,
        ]);
        Storage::disk(Document::STORAGE_DISK)->put('sk/download.pdf', 'content');
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nama_dokumen' => 'SK Download',
            'file_path' => 'sk/download.pdf',
        ]);

        $pimpinan = User::factory()->pimpinan()->create();
        // Pimpinan has dokumen read, should succeed
        $this->actingAs($pimpinan)->get(route('rbac.pegawai.history-attachments.download', ['employee' => $employee, 'type' => 'rank', 'history' => $history]))->assertOk();

        // Remove dokumen_sk.read
        Role::where('name', 'pimpinan')->firstOrFail()->permissions()->detach(Permission::where('name', 'dokumen_sk.read')->firstOrFail()->id);
        $pimpinan->refresh();
        $this->actingAs($pimpinan)->get(route('rbac.pegawai.history-attachments.download', ['employee' => $employee, 'type' => 'rank', 'history' => $history]))->assertForbidden();

        // Without employees.read also forbidden
        // Pegawai trying to access other employee should be forbidden via scope
        $pegawaiUser = User::factory()->pegawai()->create();
        Role::where('name', 'pegawai')->firstOrFail()->permissions()->syncWithoutDetaching([Permission::where('name', 'employees.read')->firstOrFail()->id, Permission::where('name', 'dokumen_sk.read')->firstOrFail()->id, Permission::where('name', 'employee_histories.read')->firstOrFail()->id]);
        $this->actingAs($pegawaiUser)->get(route('rbac.pegawai.history-attachments.download', ['employee' => $employee, 'type' => 'rank', 'history' => $history]))->assertForbidden();
    }

    public function test_dashboard_pegawai_legacy_redirect(): void
    {
        $employee = $this->employeeWithReferences();
        $superAdmin = User::factory()->superAdmin()->create();
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($superAdmin)->get(route('dashboard.pegawai.show', $employee))->assertRedirect(route('pegawai.show', $employee));
        $this->actingAs($admin)->get(route('dashboard.pegawai.show', $employee))->assertRedirect(route('rbac.pegawai.show', $employee));
    }
}
