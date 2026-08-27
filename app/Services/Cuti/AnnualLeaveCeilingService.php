<?php

namespace App\Services\Cuti;

use App\Models\Appointment;
use App\Models\Employee;
use Illuminate\Support\Carbon;

final class AnnualLeaveCeilingService
{
    /**
     * Menentukan plafon maksimum tanpa mengubahnya menjadi entitlement otomatis.
     * PPPK gagal tertutup ke 12 hari bila interval kontrak resminya tidak lengkap.
     */
    public function maximumFor(
        Employee $employee,
        int $balanceYear,
        int $usageN2,
        int $usageN1,
    ): int {
        $policyMaximum = $usageN2 === 0 && $usageN1 === 0 ? 24 : 18;

        $employee->loadMissing('jenisPegawai');

        if (mb_strtoupper((string) $employee->jenisPegawai?->nama) !== 'PPPK') {
            return $policyMaximum;
        }

        $contractEnd = $employee->tanggal_akhir_kontrak;

        if ($contractEnd === null) {
            return 12;
        }

        $evaluationDate = Carbon::create($balanceYear, 12, 31)->endOfDay();
        $appointment = Appointment::query()
            ->where('employee_id', $employee->id)
            ->where('jenis_pengangkatan', 'PPPK')
            ->whereDate('tmt_pengangkatan', '<=', $evaluationDate->toDateString())
            ->orderByDesc('tmt_pengangkatan')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $contractStart = $appointment?->tmt_pengangkatan;

        if ($contractStart === null || $contractEnd->lessThan($contractStart)) {
            return 12;
        }

        $balanceYearStart = Carbon::create($balanceYear, 1, 1)->startOfDay();

        if ($contractEnd->copy()->endOfDay()->lessThan($balanceYearStart)
            || $contractStart->copy()->startOfDay()->greaterThan($evaluationDate)) {
            return 12;
        }

        // Batas stakeholder bersifat ketat: tepat dua/tiga tahun belum masuk tingkat berikutnya.
        if ($contractStart->copy()->addYears(3)->lessThan($contractEnd)) {
            return $policyMaximum;
        }

        if ($contractStart->copy()->addYears(2)->lessThan($contractEnd)) {
            return min(18, $policyMaximum);
        }

        return 12;
    }
}
