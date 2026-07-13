<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceService;

/**
 * Orkestrasi input saldo awal cuti tahunan dari halaman admin.
 * Action menjaga controller tetap tipis dan memastikan mapping nama form ke bucket domain berada di satu tempat.
 */
class SetOpeningLeaveBalanceAction
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Employee $employee, array $data, User $actor): LeaveBalance
    {
        return $this->balances->setOpeningBalance($employee, (int) $data['tahun'], [
            'n2' => (int) $data['sisa_n2'],
            'n1' => (int) $data['sisa_n1'],
            'current' => (int) $data['sisa_tahun_berjalan'],
        ], (string) $data['reason'], $actor);
    }
}
