<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\PositionHistory;
use Illuminate\Database\Eloquent\Collection;

class ListPositionHistoriesAction
{
    /**
     * Mengambil riwayat jabatan pegawai beserta referensi jabatan, eselon, dan unit kerja.
     *
     * @return Collection<int, PositionHistory>
     */
    public function execute(Employee $employee): Collection
    {
        return $employee->positionHistories()
            ->with(['jabatan', 'jenisJabatan', 'eselon', 'unitKerja'])
            ->orderByDesc('tmt_jabatan')
            ->orderByDesc('created_at')
            ->get();
    }
}
