<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Employee;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChangeEmployeeStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);

        Storage::fake('public');
    }

    public function test_super_admin_can_change_employee_status_without_attachment(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('nama', 'Mutasi')->firstOrFail();

        $response = $this->actingAs($admin)->postWithCsrf(route('super-admin.status-pegawai.store'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $mutasi->id,
            'tanggal' => '2026-08-01',
            'alasan' => 'Pindah unit kerja',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $employee->refresh();
        $this->assertSame($mutasi->id, $employee->status_pegawai_id);
        $this->assertSame('Mutasi', $employee->status_aktif);
        $this->assertSame('Pindah unit kerja', $employee->status_alasan);
        $this->assertNull($employee->status_berkas_path);

        // Tanpa berkas, tidak ada dokumen yang tercipta.
        $this->assertDatabaseCount('documents', 0);

        // Pegawai bersangkutan menerima notifikasi in-app.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'status_pegawai.diubah',
        ]);
    }

    public function test_change_status_with_attachment_creates_single_document(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $pensiun = RefStatusPegawai::where('nama', 'Pensiun')->firstOrFail();

        $response = $this->actingAs($admin)->postWithCsrf(route('super-admin.status-pegawai.store'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $pensiun->id,
            'tanggal' => '2026-08-01',
            'alasan' => 'Batas usia pensiun',
            'berkas' => UploadedFile::fake()->create('sk-pensiun.pdf', 200, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $employee->refresh();
        $this->assertSame('Pensiun', $employee->status_aktif);
        $this->assertNotNull($employee->status_berkas_path);
        $this->assertNotNull($employee->status_nomor_berkas);

        $document = Document::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('sk_status_pegawai', $document->jenis_dokumen);
        $this->assertSame($employee->status_berkas_path, $document->file_path);
        Storage::disk(Document::STORAGE_DISK)->assertExists($document->file_path);
    }

    public function test_second_change_with_new_attachment_replaces_old_document_and_deletes_old_file(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('nama', 'Mutasi')->firstOrFail();
        $pensiun = RefStatusPegawai::where('nama', 'Pensiun')->firstOrFail();

        $this->actingAs($admin)->postWithCsrf(route('super-admin.status-pegawai.store'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $mutasi->id,
            'tanggal' => '2026-08-01',
            'alasan' => 'Alasan pertama',
            'berkas' => UploadedFile::fake()->create('sk-mutasi.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $employee->refresh();
        $oldFilePath = $employee->status_berkas_path;
        $this->assertNotNull($oldFilePath);
        Storage::disk(Document::STORAGE_DISK)->assertExists($oldFilePath);
        $this->assertSame(1, Document::where('employee_id', $employee->id)->count());

        $this->actingAs($admin)->postWithCsrf(route('super-admin.status-pegawai.store'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $pensiun->id,
            'tanggal' => '2026-09-01',
            'alasan' => 'Alasan kedua',
            'berkas' => UploadedFile::fake()->create('sk-pensiun.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $employee->refresh();
        $this->assertSame('Pensiun', $employee->status_aktif);
        $this->assertSame('Alasan kedua', $employee->status_alasan);

        // Hanya satu dokumen SK status yang tersisa (yang lama ditimpa/dihapus).
        $this->assertSame(1, Document::where('employee_id', $employee->id)->count());
        $newFilePath = $employee->status_berkas_path;
        $this->assertNotSame($oldFilePath, $newFilePath);

        Storage::disk(Document::STORAGE_DISK)->assertMissing($oldFilePath);
        Storage::disk(Document::STORAGE_DISK)->assertExists($newFilePath);
    }

    public function test_change_without_new_attachment_removes_previous_document_and_file(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $mutasi = RefStatusPegawai::where('nama', 'Mutasi')->firstOrFail();
        $pensiun = RefStatusPegawai::where('nama', 'Pensiun')->firstOrFail();

        $this->actingAs($admin)->postWithCsrf(route('super-admin.status-pegawai.store'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $mutasi->id,
            'tanggal' => '2026-08-01',
            'alasan' => 'Alasan pertama',
            'berkas' => UploadedFile::fake()->create('sk-mutasi.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $employee->refresh();
        $oldFilePath = $employee->status_berkas_path;
        $this->assertNotNull($oldFilePath);

        $this->actingAs($admin)->postWithCsrf(route('super-admin.status-pegawai.store'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $pensiun->id,
            'tanggal' => '2026-09-01',
            'alasan' => 'Alasan kedua tanpa berkas',
        ])->assertRedirect();

        $employee->refresh();
        $this->assertNull($employee->status_berkas_path);
        $this->assertNull($employee->status_nomor_berkas);
        $this->assertDatabaseCount('documents', 0);
        Storage::disk(Document::STORAGE_DISK)->assertMissing($oldFilePath);
    }

    public function test_can_change_status_to_values_beyond_original_enum(): void
    {
        // Kolom employees.status_aktif awalnya dibuat sebagai enum Postgres terbatas
        // (Aktif, Non-Aktif, Pensiun, Mutasi). Status di luar keempat nilai itu
        // (mis. "Pemberhentian Sementara") harus tetap bisa disimpan sejak kolom
        // diubah menjadi string bebas.
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $pemberhentian = RefStatusPegawai::where('nama', 'Pemberhentian Sementara')->firstOrFail();

        $response = $this->actingAs($admin)->postWithCsrf(route('super-admin.status-pegawai.store'), [
            'pegawai_id' => $employee->id,
            'status_pegawai_id' => $pemberhentian->id,
            'tanggal' => '2026-08-01',
            'alasan' => 'Diberhentikan sementara dari tugas',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $this->assertSame('Pemberhentian Sementara', $employee->refresh()->status_aktif);
    }

    public function test_admin_kepegawaian_cannot_access_status_pegawai_page(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->get(route('super-admin.status-pegawai.index'))
            ->assertForbidden();
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
