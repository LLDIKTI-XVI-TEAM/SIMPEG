<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Services\Cuti\AnnualLeaveEligibilityPolicy;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use App\Services\Cuti\LeaveBalanceService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnnualLeaveEligibilitySourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        Carbon::setTestNow('2025-02-28 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Regresi PostgreSQL: urutan insert riwayat tidak boleh menentukan awal masa kerja.
     * TMT 29 Februari harus beranniversary pada 28 Februari di tahun non-kabisat.
     */
    public function test_replay_dan_ketersediaan_tahunan_memakai_tmt_non_null_terawal_pada_setiap_urutan_insert(): void
    {
        $employees = [
            $this->employeeWithAppointments(['2024-03-01', null, '2024-02-29']),
            $this->employeeWithAppointments([null, '2024-02-29', '2024-03-01']),
        ];
        $asOf = Carbon::parse('2025-02-28 09:00:00');
        $policy = app(AnnualLeaveEligibilityPolicy::class);
        $recalculation = app(LeaveBalanceRecalculationService::class);
        $balances = app(LeaveBalanceService::class);

        foreach ($employees as $employee) {
            $this->assertTrue(
                $policy->isEligible($employee, $asOf),
                'Hak tahunan harus aktif tepat pada anniversary no-overflow dari TMT 29 Februari.',
            );

            $recalculation->recalculateForSystem(
                $employee,
                2025,
                'Regresi sumber TMT masa kerja deterministik.',
                'SIMPEG Scheduler',
            );

            $this->assertDatabaseHas('leave_balances', [
                'employee_id' => $employee->id,
                'tahun' => 2025,
                'jatah_awal' => 12,
                'sisa_tahun_berjalan' => 12,
            ]);
            $this->assertSame(12, $balances->availableFor($employee, 2025, $asOf));
        }
    }

    /** @param list<string|null> $tmts */
    private function employeeWithAppointments(array $tmts): Employee
    {
        $employee = Employee::factory()->create();

        foreach ($tmts as $index => $tmt) {
            Appointment::query()->create([
                'employee_id' => $employee->id,
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => $tmt,
                'no_sk' => 'SK-TMT-'.$employee->id.'-'.$index,
                'tanggal_sk' => $tmt,
            ]);
        }

        return $employee;
    }
}
