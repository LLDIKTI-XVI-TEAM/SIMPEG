<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_pegawai_menerima_preview_saldo_milik_sendiri_pada_tahun_tanggal_mulai(): void
    {
        $employee = $this->employeeWithAppointment('2024-01-01');
        $otherEmployee = $this->employeeWithAppointment('2024-01-01');
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'jatah_awal' => 12,
            'carry_over' => 3,
            'terpakai' => 2,
            'sisa' => 13,
            'sisa_n2' => 0,
            'sisa_n1' => 3,
            'sisa_tahun_berjalan' => 10,
            'terpakai_tahun_berjalan' => 2,
            'hangus' => 0,
        ]);
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT,
            'amount' => 3,
            'source_year' => 2027,
            'reason' => 'Koreksi administratif untuk pengujian preview.',
            'created_by' => $user->id,
            'occurred_at' => now(),
        ]);
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
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.jatah_dasar', 12)
            ->assertJsonPath('data.carry_over', 3)
            ->assertJsonPath('data.terpakai_final', 2)
            ->assertJsonPath('data.koreksi_administratif', 3)
            ->assertJsonPath('data.saldo_aktual', 13)
            ->assertJsonPath('data.dialokasikan_aktif', 0)
            ->assertJsonPath('data.saldo_dapat_diajukan', 13)
            ->assertJsonPath('data.bucket.n1', 3)
            ->assertJsonPath('data.bucket.current', 10);
    }

    public function test_preview_entitlement_virtual_tidak_menulis_saldo_hanya_karena_form_diminta(): void
    {
        $employee = $this->employeeWithAppointment('2024-01-01');
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);

        $this->actingAs($user)
            ->getJson(route('api.v1.cuti.balance-preview', ['tanggal_mulai' => '2027-02-03']))
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.jatah_dasar', 12)
            ->assertJsonPath('data.saldo_aktual', 12)
            ->assertJsonPath('data.saldo_dapat_diajukan', 12);

        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
        ]);
        $this->assertDatabaseCount('leave_balance_ledger', 0);
    }

    public function test_preview_menolak_akun_yang_tidak_terhubung_ke_pegawai(): void
    {
        $user = User::factory()->pegawai()->create(['employee_id' => null]);

        $this->actingAs($user)
            ->getJson(route('api.v1.cuti.balance-preview', ['tanggal_mulai' => '2027-02-03']))
            ->assertForbidden();
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

    private function employeeWithAppointment(string $tmt): Employee
    {
        $employee = Employee::factory()->create();
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-PREVIEW-'.$employee->id,
            'tanggal_sk' => $tmt,
        ]);

        return $employee;
    }
}
