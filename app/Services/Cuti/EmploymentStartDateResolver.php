<?php

namespace App\Services\Cuti;

use App\Models\Appointment;
use App\Models\Employee;
use Illuminate\Support\Carbon;

final class EmploymentStartDateResolver
{
    /**
     * Mengambil TMT pengangkatan non-null paling awal sebagai dasar masa kerja.
     * Relasi koleksi dipakai agar pemanggil batch yang telah eager-load tidak memicu N+1.
     */
    public function earliestAppointmentTmt(Employee $employee): ?Carbon
    {
        $employee->loadMissing('appointments');

        $appointment = $employee->appointments
            ->filter(fn (Appointment $appointment): bool => $appointment->tmt_pengangkatan !== null)
            ->sortBy([
                ['tmt_pengangkatan', 'asc'],
                ['id', 'asc'],
            ])
            ->first();

        return $appointment?->tmt_pengangkatan?->copy()->startOfDay();
    }
}
