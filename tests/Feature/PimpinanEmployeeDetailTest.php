<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Models\PositionHistory;
use App\Models\RefJenisJabatan;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PimpinanEmployeeDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_pimpinan_sees_a_real_read_only_employee_detail_without_sensitive_identifiers(): void
    {
        $pensiun = RefStatusPegawai::firstOrCreate(
            ['nama' => 'Pensiun'],
            ['keterangan' => 'Pegawai pensiun'],
        );
        $employee = Employee::factory()->lengkap()->create([
            'nama_lengkap' => 'Pegawai Detail Aktual',
            'jabatan_terakhir' => 'Analis Kepegawaian',
            'status_pegawai_id' => $pensiun->id,
            'status_aktif' => 'Pensiun',
            'tanggal_pensiun' => '2040-01-01',
        ]);
        $pimpinan = User::factory()->pimpinan()->create();

        $this->actingAs($pimpinan)
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertSee('Pegawai Detail Aktual')
            ->assertSee('Analis Kepegawaian')
            ->assertSee('Pensiun')
            ->assertSee('bg-danger/10', false)
            ->assertSee('aria-label="Navigasi detail pegawai"', false)
            ->assertSee('aria-controls="pimpinan-panel-info"', false)
            ->assertSee('id="pimpinan-panel-info"', false)
            ->assertSee('history-export-unavailable', false)
            ->assertDontSee($employee->nik)
            ->assertDontSee('/pimpinan/laporan/pegawai/custom', false);
    }

    public function test_pimpinan_employee_list_uses_real_employee_rows(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Daftar Aktual',
            'jabatan_terakhir' => 'Pranata Komputer',
        ]);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.index'))
            ->assertOk()
            ->assertSee('Pegawai Daftar Aktual')
            ->assertSee('Pranata Komputer')
            ->assertSee('type="submit"', false)
            ->assertSee(route('pimpinan.pegawai.show', $employee), false)
            ->assertSee('aria-label="Detail pegawai Pegawai Daftar Aktual"', false)
            ->assertDontSee('href="#"', false);
    }

    public function test_pimpinan_sees_read_only_family_and_active_supervisor_without_family_nik(): void
    {
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Keluarga Aktual']);
        $familyNik = '7171010101010001';
        EmployeeFamily::create([
            'employee_id' => $employee->id,
            'nama_anggota' => 'Siti Keluarga Aktual',
            'hubungan' => 'Istri',
            'nik' => $familyNik,
            'tempat_lahir' => 'Manado',
            'tanggal_lahir' => '1990-05-10',
            'jenis_kelamin' => 'P',
            'status_tunjangan' => true,
            'pekerjaan' => 'Guru',
        ]);
        $supervisor = Employee::factory()->create([
            'nama_lengkap' => 'Supervisor Aktual',
            'jabatan_terakhir' => 'Kepala Bagian Akademik',
        ]);
        $unit = RefUnitKerja::create(['nama' => 'Bagian Akademik']);
        $jenisJabatan = RefJenisJabatan::create([
            'nama' => 'Jabatan Struktural',
            'maks_usia_pensiun' => 60,
        ]);
        PositionHistory::create([
            'employee_id' => $supervisor->id,
            'nama_jabatan' => 'Kepala Bagian Akademik',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'unit_kerja_id' => $unit->id,
            'tmt_jabatan' => '2025-01-01',
            'no_sk' => 'SK-SUPERVISOR-001',
            'tanggal_sk' => '2025-01-01',
            'is_latest' => true,
        ]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => '2025-01-01',
        ]);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.pegawai.show', $employee))
            ->assertOk()
            ->assertSee('Data Keluarga')
            ->assertSee('Siti Keluarga Aktual')
            ->assertSee('Istri')
            ->assertSee('Kepala Bagian/Supervisor Aktif')
            ->assertSee('Supervisor Aktual')
            ->assertSee('Kepala Bagian Akademik')
            ->assertSee('Bagian Akademik')
            ->assertDontSee($familyNik)
            ->assertDontSee('Tambah Keluarga')
            ->assertDontSee('Edit Keluarga')
            ->assertDontSee('Hapus Keluarga');
    }
}
