<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Services\Cuti\LeaveBalanceService;
use Illuminate\Support\Carbon;

/**
 * Menyediakan satu payload saldo cuti untuk form Blade dan endpoint preview AJAX.
 * Action ini menjaga kedua surface memakai aturan eligibility dan bucket yang sama.
 */
class PreviewLeaveBalanceAction
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    /**
     * @return array{
     *     tahun:int,
     *     tanggal_acuan:string,
     *     eligible:bool,
     *     jatah_dasar:int,
     *     carry_over:int,
     *     terpakai_final:int,
     *     koreksi_administratif:int,
     *     saldo_aktual:int,
     *     dialokasikan_aktif:int,
     *     saldo_dapat_diajukan:int,
     *     bucket:array{n2:int,n1:int,current:int}
     * }
     */
    public function execute(Employee $employee, Carbon $asOf): array
    {
        return $this->balances->previewFor($employee, $asOf);
    }
}
