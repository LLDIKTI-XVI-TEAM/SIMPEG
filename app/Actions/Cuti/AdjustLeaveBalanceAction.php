<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceService;

/**
 * Orkestrasi koreksi manual saldo cuti.
 * Rule clamp debit tetap berada di service supaya semua surface memakai perlindungan saldo negatif yang sama.
 */
class AdjustLeaveBalanceAction
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Employee $employee, array $data, User $actor): LeaveBalance
    {
        return $this->balances->adjustBalance(
            $employee,
            (int) $data['tahun'],
            (string) $data['bucket'],
            (int) $data['amount'],
            (string) $data['reason'],
            $actor,
        );
    }
}
