<?php

namespace Tests\Support;

use App\Models\Employee;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use App\Services\Cuti\LeaveUsageRecordService;
use App\Services\Cuti\ManualExternalApprovalChainService;
use App\Services\WorkdayCalculator;
use Illuminate\Support\Carbon;

trait RecordsHistoricalAnnualLeaveUsage
{
    /**
     * Membentuk projection fixture dari fakta Cuti di Luar SIMPEG yang aktif.
     * Tidak ada set agregat atau fakta nol sintetis: setiap baris memakai sumber
     * manual_external dan snapshot dua tahap persetujuan eksternal.
     *
     * @param  array<int, int>  $usageByYear
     */
    private function recordHistoricalAnnualUsage(
        Employee $employee,
        array $usageByYear,
        User $actor,
        string $note,
    ): void {
        $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();

        $recorded = false;

        foreach ($usageByYear as $year => $workdays) {
            if ($workdays < 1) {
                continue;
            }

            [$start, $end] = $this->periodWithWorkdays((int) $year, $workdays);
            app(LeaveUsageRecordService::class)->recordManual(
                $employee,
                $annual,
                $start->toDateString(),
                $end->toDateString(),
                $workdays,
                $note,
                null,
                null,
                app(ManualExternalApprovalChainService::class)->normalize([
                    [
                        'step_type' => 'kepala_bagian',
                        'approver_source' => 'external_official',
                        'approver_employee_id' => null,
                        'approver_name' => 'Pejabat Kepala Bagian',
                        'approver_position' => 'Kepala Bagian',
                        'approver_institution' => 'LLDIKTI Wilayah XVI',
                        'acted_on' => now(config('app.timezone'))->toDateString(),
                        'decision_note' => null,
                    ],
                    [
                        'step_type' => 'pybmc',
                        'approver_source' => 'external_official',
                        'approver_employee_id' => null,
                        'approver_name' => 'Pejabat PYBMC',
                        'approver_position' => 'PYBMC',
                        'approver_institution' => 'LLDIKTI Wilayah XVI',
                        'acted_on' => now(config('app.timezone'))->toDateString(),
                        'decision_note' => null,
                    ],
                ]),
                $actor,
                [],
            );
            $recorded = true;
        }

        if (! $recorded) {
            app(LeaveBalanceRecalculationService::class)->recalculate(
                $employee,
                min(array_keys($usageByYear)),
                $actor,
                $note,
            );
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periodWithWorkdays(int $year, int $workdays): array
    {
        $start = Carbon::create($year, 2, 1, 0, 0, 0, config('app.timezone'))->startOfDay();
        $end = $start->copy();
        $calculator = app(WorkdayCalculator::class);

        while ($calculator->calculate($start, $end) < $workdays) {
            $end->addDay();
        }

        return [$start, $end];
    }
}
