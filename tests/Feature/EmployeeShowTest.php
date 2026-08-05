<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefAgama;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJenisCuti;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefJenjangPendidikan;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\SalaryHistory;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_admin_kepegawaian_can_show_employee_detail_by_id(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->employeeWithReferences(['nama_lengkap' => 'Siti Aminah']);

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Detail pegawai berhasil diambil.')
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.nama_lengkap', 'Siti Aminah')
            ->assertJsonPath('employee.jenis_pegawai.nama', 'PNS')
            ->assertJsonPath('employee.agama.nama', 'Islam')
            ->assertJsonPath('employee.status_kawin.nama', 'Menikah');
    }

    public function test_super_admin_can_show_employee_detail_by_id(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = $this->employeeWithReferences();

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertOk()
            ->assertJsonPath('employee.id', $employee->id);
    }

    public function test_employee_detail_page_prioritizes_manual_retirement_date_and_falls_back_to_bup(): void
    {
        EwsConfig::setVal('pensiun_required_age_years', '60');
        $user = User::factory()->adminKepegawaian()->create();

        // 1. Employee with manual date
        $employeeWithManual = $this->employeeWithReferences([
            'tanggal_lahir' => '1970-01-01',
            'tanggal_pensiun' => '2042-05-15',
        ]);

        $this->actingAs($user)
            ->get(route('pegawai.show', $employeeWithManual->id))
            ->assertOk()
            ->assertSee('15-05-2042', false)
            ->assertDontSee('01-01-2030', false);

        // 2. Employee without manual date (falls back to BUP)
        $employeeWithBup = $this->employeeWithReferences([
            'tanggal_lahir' => '1970-01-01',
            'tanggal_pensiun' => null,
        ]);

        $this->actingAs($user)
            ->get(route('pegawai.show', $employeeWithBup->id))
            ->assertOk()
            ->assertSee('01-01-2030', false);
    }

    public function test_employee_detail_page_uses_persisted_promotion_and_kgb_snapshots(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->employeeWithReferences([
            'tanggal_kenaikan_pangkat_berikutnya' => '2035-06-15',
            'tanggal_kgb_berikutnya' => null,
        ]);

        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => RefGolongan::where('kode', 'III/a')->firstOrFail()->id,
            'tmt_pangkat' => '2020-04-01',
            'is_latest' => true,
        ]);
        SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => '2020-01-01',
            'gaji_pokok' => 5000000,
            'is_latest' => true,
        ]);

        $this->actingAs($user)
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('15-06-2035', false)
            ->assertDontSee('01-04-2024', false)
            ->assertDontSee('01-01-2022', false);
    }

    public function test_detail_page_menampilkan_kontrol_kepala_bagian_hanya_bila_role_dan_permission_memenuhi_syarat(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('Ubah Kepala Bagian', false)
            ->assertSee(route('pegawai.assign-atasan', $employee->id), false);

        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.update')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertDontSee('Ubah Kepala Bagian', false);
    }

    public function test_detail_page_menyediakan_form_penghapusan_kepala_bagian_yang_eksplisit(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('id="assign-kepala-bagian-form"', false)
            ->assertSee('Hapus Kepala Bagian lalu simpan', false)
            ->assertSee('requestSubmit()', false);
    }

    public function test_detail_page_mencegah_submit_nama_kepala_bagian_tanpa_kandidat_terpilih(): void
    {
        $employee = $this->employeeWithReferences();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('@submit="validateSupervisorSelection($event)"', false)
            ->assertSee('Pilih kandidat Kepala Bagian dari hasil pencarian sebelum menyimpan.', false)
            ->assertSee('role="alert"', false)
            ->assertSee('supervisorClearConfirmed', false)
            ->assertSee('text-xs text-muted font-sans', false);
    }

    public function test_detail_page_memulihkan_label_kepala_bagian_dari_id_tervalidasi_bukan_input_browser(): void
    {
        $employee = $this->employeeWithReferences();
        $candidate = $this->employeeWithReferences(['nama_lengkap' => 'Kandidat Tersimpan']);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->withSession(['_old_input' => [
                'kepala_bagian_id' => $candidate->id,
                'kepala_bagian_label' => '<script>window.xss = true</script>',
            ]])
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('Kandidat Tersimpan', false)
            ->assertDontSee('window.xss = true', false);
    }

    public function test_employee_detail_response_includes_kepala_lembaga_marker(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->employeeWithReferences(['is_kepala_lembaga' => true]);

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertOk()
            ->assertJsonPath('employee.is_kepala_lembaga', true);
    }

    public function test_employee_detail_response_includes_erd_relations(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = $this->employeeWithReferences(['nama_lengkap' => 'Detail Lengkap']);
        $supervisor = $this->employeeWithReferences([
            'nama_lengkap' => 'Atasan Langsung',
            'email' => 'atasan@example.test',
        ]);

        $this->seedEmployeeDetail($employee, $supervisor);

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertOk()
            ->assertJsonPath('employee.families.0.nama_anggota', 'Budi Detail')
            ->assertJsonPath('employee.appointments.0.jenis_pengangkatan', 'PNS')
            ->assertJsonPath('employee.rank_histories.0.golongan.kode', 'III/a')
            ->assertJsonPath('employee.position_histories.0.jenis_jabatan.nama', 'Struktural')
            ->assertJsonPath('employee.position_histories.0.unit_kerja.nama', 'Kepala Bagian Umum')
            ->assertJsonPath('employee.salary_histories.0.gaji_pokok', '5000000.00')
            ->assertJsonPath('employee.discipline_records.0.jenis_hukuman', 'Ringan')
            ->assertJsonPath('employee.education_histories.0.jenjang.nama', 'D4 / S1')
            ->assertJsonPath('employee.documents.0.nama_dokumen', 'SK Pangkat')
            ->assertJsonPath('employee.supervisor_assignments.0.supervisor.nama_lengkap', 'Atasan Langsung')
            ->assertJsonPath('employee.leave_balances.0.tahun', 2026)
            ->assertJsonPath('employee.leave_requests.0.jenis_cuti.nama', 'Cuti Tahunan')
            ->assertJsonPath('employee.ews_alerts.0.type', 'KGB');
    }

    public function test_pegawai_cannot_show_other_employee_detail_by_admin_endpoint(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = $this->employeeWithReferences();

        $this->actingAs($user);
        $response = $this->getJson("/api/v1/pegawai/{$employee->id}");

        $response->assertForbidden();
    }

    public function test_pegawai_can_show_own_detail_from_session(): void
    {
        $employee = $this->employeeWithReferences(['nama_lengkap' => 'Pegawai Login']);
        $otherEmployee = $this->employeeWithReferences([
            'nama_lengkap' => 'Pegawai Lain',
            'email' => 'pegawai.lain@example.test',
        ]);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya');

        $response->assertOk()
            ->assertJsonPath('message', 'Detail profil pegawai berhasil diambil.')
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.nama_lengkap', 'Pegawai Login')
            ->assertJsonMissing(['id' => $otherEmployee->id]);
    }

    public function test_pegawai_without_linked_employee_gets_not_found_on_own_detail(): void
    {
        $user = User::factory()->pegawai()->create(['employee_id' => null]);

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya');

        $response->assertNotFound()
            ->assertJsonPath('message', 'Data pegawai untuk akun ini belum terhubung.');
    }

    public function test_admin_kepegawaian_cannot_use_pegawai_profile_endpoint(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya');

        $response->assertForbidden();
    }

    public function test_pegawai_profile_endpoint_requires_read_self_permission(): void
    {
        $role = Role::where('name', 'pegawai')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.read_self')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $employee = $this->employeeWithReferences();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya');

        $response->assertForbidden();
    }

    public function test_missing_employee_detail_returns_not_found(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/pegawai/'.Str::uuid());

        $response->assertNotFound();
    }

    private function employeeWithReferences(array $attributes = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'agama_id' => RefAgama::where('nama', 'Islam')->firstOrFail()->id,
            'status_kawin_id' => RefStatusPerkawinan::where('nama', 'Menikah')->firstOrFail()->id,
            'jenis_pegawai_id' => RefJenisPegawai::where('nama', 'PNS')->firstOrFail()->id,
            'email' => fake()->unique()->safeEmail(),
        ], $attributes));
    }

    private function seedEmployeeDetail(Employee $employee, Employee $supervisor): void
    {
        EmployeeFamily::create([
            'employee_id' => $employee->id,
            'nama_anggota' => 'Budi Detail',
            'hubungan' => 'Anak',
            'tanggal_lahir' => '2015-02-01',
            'jenis_kelamin' => 'L',
            'status_tunjangan' => true,
        ]);

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2010-01-01',
            'no_sk' => 'SK-ANGKAT-001',
            'tanggal_sk' => '2009-12-20',
        ]);

        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => RefGolongan::where('kode', 'III/a')->firstOrFail()->id,
            'tmt_pangkat' => '2022-04-01',
            'no_sk' => 'SK-PANGKAT-001',
            'tanggal_sk' => '2022-03-15',
            'is_latest' => true,
        ]);

        PositionHistory::create([
            'employee_id' => $employee->id,
            'nama_jabatan' => 'Analis Kepegawaian',
            'jenis_jabatan_id' => RefJenisJabatan::where('nama', 'Struktural')->firstOrFail()->id,
            'eselon_id' => RefEselon::where('kode', 'IV.a')->firstOrFail()->id,
            'unit_kerja_id' => RefUnitKerja::where('nama', 'Kepala Bagian Umum')->firstOrFail()->id,
            'tmt_jabatan' => '2023-01-01',
            'no_sk' => 'SK-JABATAN-001',
            'tanggal_sk' => '2022-12-15',
            'is_latest' => true,
        ]);

        SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => '2024-01-01',
            'gaji_pokok' => 5000000,
            'no_sk' => 'SK-KGB-001',
            'tanggal_sk' => '2023-12-20',
            'is_latest' => true,
        ]);

        DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Ringan',
            'deskripsi' => 'Teguran tertulis',
            'tanggal_mulai' => '2024-02-01',
            'tanggal_berakhir' => '2024-03-01',
            'no_sk' => 'SK-DISIPLIN-001',
            'tanggal_sk' => '2024-01-25',
            'is_active' => false,
        ]);

        EducationHistory::create([
            'employee_id' => $employee->id,
            'jenjang_id' => RefJenjangPendidikan::where('nama', 'D4 / S1')->firstOrFail()->id,
            'nama_institusi' => 'Universitas Contoh',
            'jurusan' => 'Administrasi Publik',
            'tahun_lulus' => 2010,
            'no_ijazah' => 'IJZ-001',
        ]);

        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'SK',
            'nama_dokumen' => 'SK Pangkat',
            'nomor_dokumen' => 'DOC-001',
            'tanggal_dokumen' => '2022-03-15',
            'file_path' => 'pegawai/sk-pangkat.pdf',
        ]);

        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_mulai' => '2024-01-01',
        ]);

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 1,
            'terpakai' => 2,
            'sisa' => 11,
        ]);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::where('nama', 'Cuti Tahunan')->firstOrFail()->id,
            'tanggal_mulai' => '2026-02-02',
            'tanggal_selesai' => '2026-02-03',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Keperluan keluarga',
            'status' => 'menunggu_approval',
        ]);

        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KGB',
            'target_date' => '2026-04-01',
            'interval_days' => 90,
            'is_processed' => false,
        ]);
    }
}
