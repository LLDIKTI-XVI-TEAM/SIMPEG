<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_view_leave_balance(): void
    {
        $response = $this->getJson('/api/v1/profil-saya/saldo-cuti');
        $response->assertRedirect('/login');
    }

    public function test_employee_can_view_own_leave_balance_via_api(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create([
            'employee_id' => $employee->id,
        ]);

        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => now()->year,
            'jatah_awal' => 12,
            'carry_over' => 2,
            'terpakai' => 4,
            'sisa' => 10,
        ]);

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya/saldo-cuti');

        $response->assertOk();
        $response->assertJsonStructure([
            'balance' => [
                'jatah_awal',
                'carry_over',
                'terpakai',
                'sisa',
                'tahun',
            ],
            'history',
        ]);

        $response->assertJsonPath('balance.sisa', 10);
    }

    public function test_employee_can_view_leave_history_via_api(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create([
            'employee_id' => $employee->id,
        ]);

        $jenisCuti = RefJenisCuti::firstOrCreate(['nama' => 'Cuti Tahunan']);

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => now()->toDateString(),
            'tanggal_selesai' => now()->addDays(2)->toDateString(),
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Liburan',
            'status' => 'disetujui',
        ]);

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/profil-saya/saldo-cuti');

        $response->assertOk();
        $response->assertJsonCount(1, 'history');
        $response->assertJsonPath('history.0.id', $leaveRequest->id);
        $response->assertJsonPath('history.0.jenis_cuti', 'Cuti Tahunan');
        $response->assertJsonPath('history.0.jumlah_hari_kerja', 3);
    }

    public function test_employee_can_view_own_leave_balance_via_web(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create([
            'employee_id' => $employee->id,
        ]);

        $this->actingAs($user);
        $response = $this->get('/dashboard/cuti/saldo');

        $response->assertOk();
        $response->assertViewIs('admin.cuti.personal-saldo');
        $response->assertViewHas('balance');
        $response->assertViewHas('history');
    }

    public function test_personal_saldo_web_tidak_membuat_saldo_saat_dibuka(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create([
            'employee_id' => $employee->id,
        ]);

        $response = $this->actingAs($user)->get('/dashboard/cuti/saldo');

        $response->assertOk();
        $response->assertSee('Saldo cuti tahunan belum tersedia', false);
        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => now()->year,
        ]);
    }
}
