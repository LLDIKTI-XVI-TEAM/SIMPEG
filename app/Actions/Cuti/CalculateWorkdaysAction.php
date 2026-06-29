<?php

namespace App\Actions\Cuti;

use App\Services\WorkdayCalculator;
use Illuminate\Support\Carbon;

/**
 * Mengoordinasikan kalkulasi hari kerja untuk form pengajuan cuti.
 * Mengembalikan jumlah hari kerja sekaligus peringatan tanggal dalam satu pengambilan data libur.
 */
class CalculateWorkdaysAction
{
    public function __construct(private readonly WorkdayCalculator $calculator) {}

    /**
     * @return array{jumlah_hari_kerja: int, warnings: list<string>}
     */
    public function execute(Carbon $start, Carbon $end): array
    {
        return $this->calculator->summarize($start, $end);
    }
}
