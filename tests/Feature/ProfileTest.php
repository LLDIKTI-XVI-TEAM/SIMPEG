<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Models\EwsAlert;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenisCuti;
use App\Models\RefJenjangPendidikan;
use App\Models\RefProgramStudi;
use App\Models\SalaryHistory;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_profile_shows_current_year_leave_balance_when_available(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => now()->year,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 5,
            'sisa' => 7,
        ]);

        $this->actingAs($user);
        $response = $this->get('/dashboard/profil?tab=cuti');

        $response->assertOk();
        $response->assertSee('Informasi Saldo Cuti ('.now()->year.')', false);
        $response->assertSee('7', false);
    }

    public function test_profile_shows_fallback_default_leave_balance_when_missing(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);
        $response = $this->get('/dashboard/profil?tab=cuti');

        $response->assertOk();
        $response->assertDontSee('Belum tersedia', false);
        $response->assertSee('Hak Efektif Tahun Ini', false);
        $response->assertSee('-', false);
    }

    public function test_profile_rule_5_menampilkan_sisa_efektif_nol_tanpa_mengubah_saldo_tercatat(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => now()->year,
            'jatah_awal' => 12,
            'carry_over' => 6,
            'terpakai' => 0,
            'sisa' => 18,
            'sisa_n2' => 0,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'besar')->firstOrFail()->id,
            'tanggal_mulai' => now()->startOfYear()->addMonths(2)->toDateString(),
            'tanggal_selesai' => now()->startOfYear()->addMonths(2)->addDays(30)->toDateString(),
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar final.',
            'status' => 'disetujui',
        ]);

        $response = $this->actingAs($user)->get('/dashboard/profil?tab=cuti');

        $response->assertOk()
            ->assertViewHas('rule5Active', true)
            ->assertViewHas('saldoCuti', fn (mixed $saldo): bool => is_array($saldo)
                && $saldo['rule_5_active'] === true
                && $saldo['saldo_dapat_diajukan'] === 0)
            ->assertSee('Hak Efektif Tahun Ini', false)
            ->assertSee('tidak dapat digunakan pada tahun Cuti Besar', false);
        $this->assertSame(18, $balance->fresh()->sisa);
    }

    public function test_profile_ews_section_uses_real_alerts_not_mock(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'SATYALANCANA',
            'target_date' => now()->addDays(90)->toDateString(),
            'interval_days' => 90,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get('/dashboard/profil');

        $response->assertOk();
        $response->assertSee('Satyalancana', false);
        $response->assertSee(route('ews.saya'), false);
        $response->assertDontSee('Mockup EWS', false);
    }

    public function test_profile_uses_employee_tanggal_pensiun(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1970-01-01',
            'tanggal_pensiun' => '2042-05-15',
        ]);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $response = $this->actingAs($user)->get('/dashboard/profil');

        $response->assertOk();
        $response->assertSee('15-05-2042', false);
        $response->assertDontSee('01-01-2028', false);
    }

    public function test_profile_uses_persisted_tmt_and_kgb_snapshots_instead_of_history_fallbacks(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => '2031-06-15',
            'tanggal_kgb_berikutnya' => null,
        ]);
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $golongan = RefGolongan::where('kode', 'III/a')->firstOrFail();

        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2026-01-01',
            'no_sk' => 'SK-RANK-PROFILE',
            'tanggal_sk' => '2026-01-10',
            'is_latest' => true,
        ]);
        SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => '2026-03-01',
            'gaji_pokok' => 4500000,
            'no_sk' => 'SK-KGB-PROFILE',
            'tanggal_sk' => '2026-03-10',
            'is_latest' => true,
        ]);

        $response = $this->actingAs($user)->get('/dashboard/profil');

        $response->assertOk();
        $response->assertSee('15-06-2031', false);
        $response->assertDontSee('01-01-2030', false);
        $response->assertDontSee('01-03-2028', false);
    }

    public function test_profile_renders_family_and_education_as_read_only_data(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $jenjang = RefJenjangPendidikan::where('nama', 'D4 / S1')->firstOrFail();
        $programStudi = RefProgramStudi::create(['nama' => 'Program Studi Referensi Profil']);

        EmployeeFamily::create([
            'employee_id' => $employee->id,
            'nama_anggota' => 'Keluarga Profil Saya',
            'hubungan' => 'Istri',
            'tanggal_lahir' => '1990-05-10',
            'jenis_kelamin' => 'P',
            'status_tunjangan' => true,
        ]);
        EducationHistory::create([
            'employee_id' => $employee->id,
            'jenjang_id' => $jenjang->id,
            'program_studi_id' => $programStudi->id,
            'nama_institusi' => 'Universitas Profil Saya',
            'jurusan' => 'Snapshot Jurusan Lama',
            'tahun_lulus' => 2010,
            'no_ijazah' => 'IJZ-PROFIL-001',
        ]);

        $response = $this->actingAs($user)->get('/dashboard/profil');

        $response->assertOk();
        $response->assertSee('Susunan Anggota Keluarga', false);
        $response->assertSee('Keluarga Profil Saya', false);
        $response->assertSee('Riwayat Pendidikan Formal', false);
        $response->assertSee('Universitas Profil Saya', false);
        $response->assertSee('Program Studi Referensi Profil', false);
        $response->assertDontSee('Snapshot Jurusan Lama', false);
        $response->assertDontSee('@click="openModal()"', false);
        $response->assertDontSee('@submit.prevent="submitForm()"', false);
        $response->assertDontSee("fetch('/api/v1/profil-saya/keluarga'", false);
        $response->assertDontSee('x-model="newKeluarga.', false);
    }

    public function test_profile_password_update_rejects_wrong_current_password(): void
    {
        $user = User::factory()->pegawai()->create([
            'password' => Hash::make('password-lama'),
        ]);

        $this->actingAs($user);
        $response = $this->from('/dashboard/profil')->post('/dashboard/profil/password', [
            'current_password' => 'salah',
            'new_password' => 'password-baru',
            'new_password_confirmation' => 'password-baru',
        ]);

        $response->assertRedirect('/dashboard/profil');
        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('password-lama', $user->refresh()->password));
    }

    public function test_profile_password_update_changes_password_when_current_password_matches(): void
    {
        $user = User::factory()->pegawai()->create([
            'password' => Hash::make('password-lama'),
        ]);

        $this->actingAs($user);
        $response = $this->post('/dashboard/profil/password', [
            'current_password' => 'password-lama',
            'new_password' => 'password-baru',
            'new_password_confirmation' => 'password-baru',
        ]);

        $response->assertRedirect('/dashboard/profil');
        $response->assertSessionHas('success');
        $this->assertTrue(Hash::check('password-baru', $user->refresh()->password));
    }

    public function test_perubahan_kata_sandi_tercatat_pada_audit_tanpa_menyimpan_kata_sandi(): void
    {
        $user = User::factory()->pegawai()->create([
            'password' => Hash::make('password-lama'),
        ]);

        $this->actingAs($user);
        $this->post('/dashboard/profil/password', [
            'current_password' => 'password-lama',
            'new_password' => 'password-baru',
            'new_password_confirmation' => 'password-baru',
        ]);

        $audit = AuditLog::query()
            ->where('event', 'UPDATE')
            ->where('auditable_type', 'User')
            ->where('auditable_id', $user->id)
            ->sole();

        // Jejak perubahan kredensial wajib ada, namun nilai maupun hash kata sandi tidak boleh
        // ikut tersimpan karena audit bersifat append-only dan dapat dibaca operator lain.
        $isiAudit = json_encode([$audit->old_values, $audit->new_values], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('password-lama', $isiAudit);
        $this->assertStringNotContainsString('password-baru', $isiAudit);
        $this->assertStringNotContainsString($user->refresh()->password, $isiAudit);
        $this->assertTrue($audit->new_values['password_changed']);
    }
}
