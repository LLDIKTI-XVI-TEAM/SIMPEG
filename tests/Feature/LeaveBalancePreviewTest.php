<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Kontrak preview saldo form cuti: data selalu milik Pegawai login dan endpoint GET
 * tidak boleh menulis entitlement/ledger hanya karena form dimuat ulang.
 */
class LeaveBalancePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pegawai_menerima_preview_saldo_milik_sendiri_pada_tahun_tanggal_mulai(): void
    {
        $employee = $this->employeeWithAppointment('2024-01-01');
        $otherEmployee = $this->employeeWithAppointment('2024-01-01');
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $admin = User::factory()->adminKepegawaian()->create();
        RefJenisCuti::query()->create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        Carbon::setTestNow('2027-08-04 09:00:00');
        $this->recordAnnualManualUsage($employee, $admin, 2025, 12);
        $this->recordAnnualManualUsage($employee, $admin, 2026, 9);
        $this->recordAnnualManualUsage($employee, $admin, 2027, 2);
        app(LeaveBalanceRecalculationService::class)->recalculate(
            $employee,
            2025,
            $admin,
            'Membentuk projection preview dari fakta pemakaian aktif.',
        );
        LeaveBalance::create([
            'employee_id' => $otherEmployee->id,
            'tahun' => 2027,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 1,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 1,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);

        $response = $this->actingAs($user)->getJson(route('api.v1.cuti.balance-preview', [
            'tanggal_mulai' => '2027-08-04',
            // Parameter asing harus diabaikan; data scope selalu berasal dari sesi login.
            'employee_id' => $otherEmployee->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('data.tahun', 2027)
            ->assertJsonPath('data.tanggal_acuan', '2027-08-04')
            ->assertJsonPath('data.rule_5_active', false)
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.jatah_dasar', 12)
            ->assertJsonPath('data.carry_over', 1)
            ->assertJsonPath('data.terpakai_final', 2)
            ->assertJsonPath('data.saldo_aktual', 13)
            ->assertJsonPath('data.dialokasikan_aktif', 0)
            ->assertJsonPath('data.saldo_dapat_diajukan', 13)
            ->assertJsonPath('data.bucket.n1', 1)
            ->assertJsonPath('data.bucket.current', 12)
            ->assertJsonMissingPath('data.koreksi_administratif');
    }

    public function test_preview_tanpa_rekonsiliasi_gagal_tertutup_dan_tidak_menulis_projection(): void
    {
        $employee = $this->employeeWithAppointment('2024-01-01');
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user)
            ->getJson(route('api.v1.cuti.balance-preview', ['tanggal_mulai' => '2027-02-03']))
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.jatah_dasar', 0)
            ->assertJsonPath('data.saldo_aktual', 0)
            ->assertJsonPath('data.saldo_dapat_diajukan', 0);

        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
        ]);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
    }

    public function test_preview_menolak_akun_yang_tidak_terhubung_ke_pegawai(): void
    {
        $user = User::factory()->pegawai()->create(['employee_id' => null]);

        $this->actingAsUnmapped($user)
            ->getJson(route('api.v1.cuti.balance-preview', ['tanggal_mulai' => '2027-02-03']))
            ->assertRedirect(route('status-akun'));
    }

    public function test_preview_mengarahkan_tamu_ke_login(): void
    {
        $this->get(route('api.v1.cuti.balance-preview', ['tanggal_mulai' => '2027-02-03']))
            ->assertRedirect('/login');
    }

    public function test_preview_menolak_super_admin_yang_tidak_memiliki_hak_mengajukan_cuti(): void
    {
        $employee = $this->employeeWithAppointment('2024-01-01');
        $user = User::factory()->superAdmin()->create(['employee_id' => $employee->id]);

        $this->actingAs($user)
            ->getJson(route('api.v1.cuti.balance-preview', ['tanggal_mulai' => '2027-02-03']))
            ->assertForbidden();
    }

    public function test_preview_memvalidasi_tanggal_mulai(): void
    {
        $employee = $this->employeeWithAppointment('2024-01-01');
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user)
            ->getJson(route('api.v1.cuti.balance-preview', ['tanggal_mulai' => 'bukan-tanggal']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tanggal_mulai');
    }

    public function test_preview_rule_5_mengembalikan_shape_yang_sama_dengan_ketersediaan_efektif_nol_tanpa_write(): void
    {
        $employee = $this->employeeWithAppointment('2018-01-01');
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'jatah_awal' => 12,
            'carry_over' => 10,
            'terpakai' => 0,
            'sisa' => 22,
            'sisa_n2' => 4,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);
        $large = RefJenisCuti::create([
            'nama' => 'Cuti Besar',
            'code' => 'besar',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => true,
        ]);
        $largeRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $large->id,
            'tanggal_mulai' => '2027-03-01',
            'tanggal_selesai' => '2027-03-31',
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar final.',
            'status' => 'disetujui',
        ]);
        LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $large->id,
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'leave_request_id' => $largeRequest->id,
            'usage_year' => 2027,
            'effective_date' => '2027-03-01',
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-31',
            'workdays' => 20,
            'administrative_note' => 'Fixture fakta Cuti Besar final.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $user->id,
        ]);
        $beforeLedger = LeaveBalanceLedger::query()->count();

        $this->actingAs($user)
            ->getJson(route('api.v1.cuti.balance-preview', ['tanggal_mulai' => '2027-08-04']))
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'tahun', 'tanggal_acuan', 'eligible', 'jatah_dasar', 'carry_over',
                'terpakai_final', 'saldo_aktual',
                'dialokasikan_aktif', 'dilindungi_penangguhan_dinas', 'saldo_dapat_diajukan', 'rule_5_active',
                'bucket' => ['n2', 'n1', 'current'],
            ]])
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.rule_5_active', true)
            ->assertJsonPath('data.jatah_dasar', 0)
            ->assertJsonPath('data.carry_over', 0)
            ->assertJsonPath('data.saldo_aktual', 0)
            ->assertJsonPath('data.dialokasikan_aktif', 0)
            ->assertJsonPath('data.dilindungi_penangguhan_dinas', 0)
            ->assertJsonPath('data.saldo_dapat_diajukan', 0)
            ->assertJsonPath('data.bucket.n2', 0)
            ->assertJsonPath('data.bucket.n1', 0)
            ->assertJsonPath('data.bucket.current', 0);

        $balance->refresh();
        $this->assertSame(22, $balance->sisa);
        $this->assertSame(4, $balance->sisa_n2);
        $this->assertSame($beforeLedger, LeaveBalanceLedger::query()->count());
    }

    public function test_preview_cuti_besar_non_final_tetap_menampilkan_saldo_efektif(): void
    {
        $employee = $this->employeeWithAppointment('2018-01-01');
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2027,
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
        $large = RefJenisCuti::create([
            'nama' => 'Cuti Besar',
            'code' => 'besar',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => true,
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $large->id,
            'tanggal_mulai' => '2027-03-01',
            'tanggal_selesai' => '2027-03-31',
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Cuti Besar belum final.',
            'status' => 'menunggu_approval',
        ]);

        $this->actingAs($user)
            ->getJson(route('api.v1.cuti.balance-preview', ['tanggal_mulai' => '2027-08-04']))
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.saldo_aktual', 18)
            ->assertJsonPath('data.saldo_dapat_diajukan', 18);
    }

    private function employeeWithAppointment(string $tmt): Employee
    {
        $jenisPns = RefJenisPegawai::query()->firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $jenisPns->id]);
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-PREVIEW-'.$employee->id,
            'tanggal_sk' => $tmt,
        ]);

        return $employee;
    }

    private function recordAnnualManualUsage(Employee $employee, User $actor, int $year, int $workdays): void
    {
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
        $date = sprintf('%d-01-02', $year);

        LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annual->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => $year,
            'effective_date' => $date,
            'start_date' => $date,
            'end_date' => $date,
            'workdays' => $workdays,
            'administrative_note' => 'Fixture fakta manual untuk preview saldo.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);
    }
}
