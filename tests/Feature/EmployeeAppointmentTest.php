<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\RefJenisPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeAppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::STORAGE_DISK);
        $this->seed(RbacSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_admin_can_view_appointment(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $appointment = Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'CPNS',
            'no_sk' => 'SK-CPNS-001',
            'tanggal_sk' => '2024-01-10',
            'tmt_pengangkatan' => '2024-02-01',
            'file_sk' => null,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/pegawai/{$employee->id}/pengangkatan");

        $response->assertOk();
        $response->assertJsonPath('appointment.no_sk', 'SK-CPNS-001');
        $response->assertJsonPath('appointment.jenis_pengangkatan', 'CPNS');
    }

    public function test_admin_can_create_appointment_with_file(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $file = UploadedFile::fake()->create('sk_pengangkatan.pdf', 500, 'application/pdf');

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/pegawai/{$employee->id}/pengangkatan", [
                'jenis_pengangkatan' => 'PNS',
                'no_sk' => 'SK-PNS-2025-001',
                'tanggal_sk' => '2025-03-01',
                'tmt_pengangkatan' => '2025-04-01',
                'file_sk' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Data SK Pengangkatan pertama berhasil disimpan.');
        $response->assertJsonPath('appointment.no_sk', 'SK-PNS-2025-001');

        $this->assertDatabaseHas('appointments', [
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'no_sk' => 'SK-PNS-2025-001',
        ]);

        $appointment = $employee->appointment()->first();
        $this->assertNotNull($appointment->file_sk);
        Storage::disk(Document::STORAGE_DISK)->assertExists($appointment->file_sk);

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pengangkatan',
            'nomor_dokumen' => 'SK-PNS-2025-001',
            'file_path' => $appointment->file_sk,
        ]);

        // Endpoint pengangkatan adalah sumber masa kerja: jenis pegawai dan
        // milestone Satyalancana harus ikut sinkron, seperti form edit pegawai.
        $employee->refresh();
        $this->assertSame(
            RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
            $employee->jenis_pegawai_id,
        );
        $this->assertDatabaseHas('employee_milestones', [
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_SATYALANCANA,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_update_existing_appointment(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'CPNS',
            'no_sk' => 'OLD-SK-001',
            'tanggal_sk' => '2024-01-01',
            'tmt_pengangkatan' => '2024-02-01',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/pegawai/{$employee->id}/pengangkatan", [
                'jenis_pengangkatan' => 'CPNS',
                'no_sk' => 'UPDATED-SK-002',
                'tanggal_sk' => '2024-01-15',
                'tmt_pengangkatan' => '2024-02-01',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('appointments', [
            'employee_id' => $employee->id,
            'no_sk' => 'UPDATED-SK-002',
        ]);
    }

    public function test_admin_can_upload_sk_for_existing_appointment(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $oldPath = 'sk/old-appointment-sk.pdf';
        Storage::disk(Document::STORAGE_DISK)->put($oldPath, 'old appointment document');
        $appointment = Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PPPK',
            'no_sk' => 'SK-PPPK-2026',
            'tanggal_sk' => '2026-01-01',
            'tmt_pengangkatan' => '2026-02-01',
            'file_sk' => $oldPath,
        ]);
        $employee->documents()->create([
            'jenis_dokumen' => 'sk_pengangkatan',
            'nama_dokumen' => 'SK Pengangkatan PPPK',
            'nomor_dokumen' => $appointment->no_sk,
            'tanggal_dokumen' => $appointment->tanggal_sk,
            'file_path' => $oldPath,
        ]);

        $file = UploadedFile::fake()->create('sk_pppk.pdf', 600, 'application/pdf');

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/pegawai/{$employee->id}/pengangkatan/upload-sk", [
                'file_sk' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Berkas SK Pengangkatan berhasil diperbarui.');

        $appointment->refresh();
        $this->assertNotNull($appointment->file_sk);
        Storage::disk(Document::STORAGE_DISK)->assertExists($appointment->file_sk);
        Storage::disk(Document::STORAGE_DISK)->assertMissing($oldPath);

        $this->assertDatabaseHas('documents', [
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'sk_pengangkatan',
            'nomor_dokumen' => 'SK-PPPK-2026',
            'file_path' => $appointment->file_sk,
        ]);
    }

    public function test_jenis_pengangkatan_only_allows_pns_cpns_pppk(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/pegawai/{$employee->id}/pengangkatan", [
                'jenis_pengangkatan' => 'HONORER',
                'no_sk' => 'SK-INVALID',
                'tanggal_sk' => '2026-01-01',
                'tmt_pengangkatan' => '2026-02-01',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['jenis_pengangkatan']);
    }

    public function test_upload_sk_without_appointment_is_rejected_without_creating_fake_appointment(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/pegawai/{$employee->id}/pengangkatan/upload-sk", [
                'file_sk' => UploadedFile::fake()->create('sk.pdf', 500, 'application/pdf'),
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['appointment']);
        $this->assertDatabaseMissing('appointments', ['employee_id' => $employee->id]);
    }
}
