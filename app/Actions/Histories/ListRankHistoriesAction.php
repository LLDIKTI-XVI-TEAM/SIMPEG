<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\RankHistory;
use Illuminate\Database\Eloquent\Collection;

class ListRankHistoriesAction
{
    /**
     * Mengambil riwayat pangkat pegawai sesuai urutan tampilan mutasi terbaru.
     *
     * @return Collection<int, RankHistory>
     */
    public function execute(Employee $employee): Collection
    {
        return $employee->rankHistories()
            ->with('golongan')
            ->orderByDesc('tmt_pangkat')
            ->orderByDesc('created_at')
            ->get();
    }
}
