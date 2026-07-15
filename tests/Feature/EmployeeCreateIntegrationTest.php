<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefAgama;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPerkawinan;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeCreateIntegrationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'ReferenceSeeder']);
        $this->artisan('db:seed', ['--class' => 'RbacSeeder']);
    }

    public function test_can_create_employee_via_ui_form()
    {
        Storage::fake('public');

        // Arrange
        $user = User::factory()->create([
            'role' => 'admin_kepegawaian',
        ]);

        $agamaId = RefAgama::first()->id;
        $jenisPegawaiId = RefJenisPegawai::first()->id;
        $statusKawinId = RefStatusPerkawinan::first()->id;

        $postData = [
            'nama_lengkap' => 'Budi Santoso Uji',
            'nip' => '199001012024011001',
            'nik' => '3273100101900001',
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'L',
            'agama_id' => $agamaId,
            'status_kawin_id' => $statusKawinId,
            'jenis_pegawai_id' => $jenisPegawaiId,
            'golongan_terakhir' => 'III/a',
            'pangkat_terakhir' => 'Penata Muda',
            'jabatan_terakhir' => 'Analis Sistem Informasi',
            'kelas_jabatan' => '7',
            'pengangkatan_tmt_pengangkatan' => '2024-01-01',
            'pengangkatan_jenis_pengangkatan' => 'PNS',
            'pengangkatan_no_sk' => 'SK-UJI-001',
            'pengangkatan_tanggal_sk' => '2023-12-01',
            'file_sk_pengangkatan' => UploadedFile::fake()->create('sk-pengangkatan.pdf', 500, 'application/pdf'),
            'is_kepala_lembaga' => true,
        ];

        // Act
        $response = $this->actingAs($user)->post(route('pegawai.store'), $postData);

        // Assert
        if (session()->has('error')) {
            dump(session('error'));
        }
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('data-pegawai'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'nama_lengkap' => 'Budi Santoso Uji',
            'nip' => '199001012024011001',
            'is_kepala_lembaga' => true,
        ]);

        $employee = Employee::where('nip', '199001012024011001')->first();

        $this->assertDatabaseHas('appointments', [
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2024-01-01 00:00:00',
            'no_sk' => 'SK-UJI-001',
            'tanggal_sk' => '2023-12-01 00:00:00',
        ]);

        $documentPath = $employee->documents()->where('jenis_dokumen', 'sk_pengangkatan')->value('file_path');
        $this->assertIsString($documentPath);
        $this->assertStringStartsWith('appointments/sk/', $documentPath);
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        $disk->assertExists($documentPath);

        // Check if detail page renders
        $detailResponse = $this->actingAs($user)->get(route('pegawai.show', $employee->id));
        $detailResponse->assertStatus(200);
        $detailResponse->assertSee('Budi Santoso Uji');
        $detailResponse->assertSee('Kepala Lembaga');
        $detailResponse->assertSee('Ya');

        $createResponse = $this->actingAs($user)->get(route('pegawai.create'));
        $createResponse->assertStatus(200);
        $createResponse->assertSee('Kepala Lembaga');
        $createResponse->assertSee('name="is_kepala_lembaga"', false);

        $editResponse = $this->actingAs($user)->get(route('pegawai.edit', $employee->id));
        $editResponse->assertStatus(200);
        $editResponse->assertSee('Kepala Lembaga');
        $editResponse->assertSee('name="is_kepala_lembaga"', false);
    }
}
