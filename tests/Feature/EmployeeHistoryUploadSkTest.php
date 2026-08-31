<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\SalaryHistory;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeHistoryUploadSkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::STORAGE_DISK);
        $this->seed(RbacSeeder::class);
    }

    public function test_admin_can_upload_sk_for_existing_rank_history(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $golongan = RefGolongan::create(['kode' => 'IV/a', 'nama' => 'Pembina']);

        $rank = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2026-04-01',
            'no_sk' => 'SK-PANGKAT-001',
            'tanggal_sk' => '2026-03-15',
            'file_sk' => null,
            'is_latest' => true,
        ]);

        $file = UploadedFile::fake()->create('sk_pangkat.pdf', 500, 'application/pdf');

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan/{$rank->id}/upload-sk", [
                'file_sk' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Berkas SK kepangkatan berhasil diperbarui.');
        $response->assertJsonStructure(['history' => ['download_url']]);

        $rank->refresh();
        $this->assertNotNull($rank->file_sk);
        Storage::disk(Document::STORAGE_DISK)->assertExists($rank->file_sk);

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pangkat',
            'nomor_dokumen' => 'SK-PANGKAT-001',
            'file_path' => $rank->file_sk,
        ]);
    }

    public function test_admin_can_upload_sk_for_existing_position_history(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $jabatan = RefJabatan::create(['nama' => 'Pranata Komputer Ahli Muda', 'is_active' => true]);

        $position = PositionHistory::create([
            'employee_id' => $employee->id,
            'jabatan_id' => $jabatan->id,
            'nama_jabatan' => $jabatan->nama,
            'tmt_jabatan' => '2026-01-01',
            'no_sk' => 'SK-JABATAN-002',
            'tanggal_sk' => '2025-12-20',
            'file_sk' => null,
            'is_latest' => true,
        ]);

        $file = UploadedFile::fake()->create('sk_jabatan.pdf', 300, 'application/pdf');

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/pegawai/{$employee->id}/riwayat-jabatan/{$position->id}/upload-sk", [
                'file_sk' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Berkas SK jabatan berhasil diperbarui.');

        $position->refresh();
        $this->assertNotNull($position->file_sk);
        Storage::disk(Document::STORAGE_DISK)->assertExists($position->file_sk);

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_jabatan',
            'nomor_dokumen' => 'SK-JABATAN-002',
            'file_path' => $position->file_sk,
        ]);
    }

    public function test_admin_can_upload_sk_for_existing_kgb_history(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $kgb = SalaryHistory::create([
            'employee_id' => $employee->id,
            'gaji_pokok' => 4500000,
            'tmt_kgb' => '2026-03-01',
            'no_sk' => 'KGB-2026-003',
            'tanggal_sk' => '2026-02-15',
            'file_sk' => null,
            'is_latest' => true,
        ]);

        $file = UploadedFile::fake()->create('sk_kgb.pdf', 400, 'application/pdf');

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/pegawai/{$employee->id}/riwayat-kgb/{$kgb->id}/upload-sk", [
                'file_sk' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Berkas SK KGB berhasil diperbarui.');

        $kgb->refresh();
        $this->assertNotNull($kgb->file_sk);
        Storage::disk(Document::STORAGE_DISK)->assertExists($kgb->file_sk);

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_kgb',
            'nomor_dokumen' => 'KGB-2026-003',
            'file_path' => $kgb->file_sk,
        ]);
    }

    public function test_upload_sk_validates_file_format(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $golongan = RefGolongan::create(['kode' => 'III/a', 'nama' => 'Penata Muda']);

        $rank = RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2026-04-01',
            'no_sk' => 'SK-PANGKAT-004',
            'tanggal_sk' => '2026-03-15',
            'file_sk' => null,
        ]);

        $file = UploadedFile::fake()->create('malicious.exe', 500, 'application/octet-stream');

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/pegawai/{$employee->id}/riwayat-kepangkatan/{$rank->id}/upload-sk", [
                'file_sk' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file_sk']);
    }
}
