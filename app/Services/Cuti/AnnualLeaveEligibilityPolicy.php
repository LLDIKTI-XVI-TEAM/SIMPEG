<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use Illuminate\Support\Carbon;

final class AnnualLeaveEligibilityPolicy
{
    public function __construct(private readonly EmploymentStartDateResolver $employmentStartDate) {}

    /**
     * Hak cuti tahunan baru aktif setelah satu tahun masa kerja sejak TMT pengangkatan.
     */
    public function isEligible(Employee $employee, Carbon $asOf): bool
    {
        $tmt = $this->employmentStartDate->earliestAppointmentTmt($employee);

        if ($tmt === null) {
            return false;
        }

        // Anniversary adalah aturan tanggal kalender; offset zona waktu tidak boleh menggeser hari kelayakan.
        return $tmt->addYearNoOverflow()->toDateString() <= $asOf->toDateString();
    }
}
