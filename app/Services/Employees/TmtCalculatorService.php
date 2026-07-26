<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\EwsConfig;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use Illuminate\Support\Carbon;

class TmtCalculatorService
{
    /**
     * Menyinkronkan snapshot tanggal turunan dari riwayat bertanggal terbaru tanpa mengubah riwayat sumber.
     */
    public function syncForEmployee(Employee $employee): void
    {
        $latestRank = $this->latestRank($employee);
        $latestSalary = $this->latestSalary($employee);

        $pangkatRequiredYears = $this->configYears('pangkat_required_years', 4);
        $kgbRequiredYears = $this->configYears('kgb_required_years', 2);

        // Tanpa sumber bertanggal, snapshot lama harus dikosongkan agar tidak dianggap sebagai fakta pegawai.
        $updates = [
            'tanggal_kenaikan_pangkat_berikutnya' => $latestRank?->tmt_pangkat?->copy()->addYearsNoOverflow($pangkatRequiredYears),
            'tanggal_kgb_berikutnya' => $latestSalary?->tmt_kgb?->copy()->addYearsNoOverflow($kgbRequiredYears),
        ];

        // Tanggal pensiun manual/import adalah data resmi sehingga kalkulasi hanya mengisi nilai yang masih kosong.
        if ($employee->tanggal_pensiun === null) {
            $pensionDate = $this->pensionDate($employee);

            if ($pensionDate !== null) {
                $updates['tanggal_pensiun'] = $pensionDate;
            }
        }

        $employee->update($updates);
    }

    private function configYears(string $key, int $default): int
    {
        return max(1, (int) EwsConfig::getVal($key, (string) $default));
    }

    private function latestRank(Employee $employee): ?RankHistory
    {
        return $employee->rankHistories()
            ->whereNotNull('tmt_pangkat')
            ->orderByDesc('tmt_pangkat')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function latestSalary(Employee $employee): ?SalaryHistory
    {
        return $employee->salaryHistories()
            ->whereNotNull('tmt_kgb')
            ->orderByDesc('tmt_kgb')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function latestPosition(Employee $employee): ?PositionHistory
    {
        return $employee->positionHistories()
            ->with(['jabatan', 'jenisJabatan'])
            ->whereNotNull('tmt_jabatan')
            ->orderByDesc('tmt_jabatan')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function pensionDate(Employee $employee): ?Carbon
    {
        $position = $this->latestPosition($employee);
        $bup = $position?->jabatan?->default_bup
            ?? $position?->jenisJabatan?->maks_usia_pensiun;

        if ($employee->tanggal_lahir === null || $bup === null) {
            return null;
        }

        return $employee->tanggal_lahir->copy()->addYearsNoOverflow($bup);
    }
}
