<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefAgama;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPerkawinan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
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
            'tmt' => '2024-01-01',
            'jenis_pengangkatan' => 'PNS',
            'nomor_sk' => 'SK-UJI-001',
            'tanggal_sk' => '2023-12-01',
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
        ]);

        $employee = Employee::where('nip', '199001012024011001')->first();

        // Check if detail page renders
        $detailResponse = $this->actingAs($user)->get(route('pegawai.show', $employee->id));
        $detailResponse->assertStatus(200);
        $detailResponse->assertSee('Budi Santoso Uji');
    }
}
