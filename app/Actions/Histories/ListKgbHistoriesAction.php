<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\SalaryHistory;
use Illuminate\Database\Eloquent\Collection;

class ListKgbHistoriesAction
{
    /**
     * Mengambil riwayat KGB pegawai sesuai urutan TMT terbaru untuk layar riwayat gaji.
     *
     * @return Collection<int, SalaryHistory>
     */
    public function execute(Employee $employee): Collection
    {
        return $employee->salaryHistories()
            ->orderByDesc('tmt_kgb')
            ->orderByDesc('created_at')
            ->get();
    }
}
