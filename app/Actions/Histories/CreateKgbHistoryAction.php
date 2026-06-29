<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\SalaryHistory;
use App\Services\EmployeeHistoryService;
use Illuminate\Http\Request;

class CreateKgbHistoryAction
{
    public function __construct(private readonly EmployeeHistoryService $service) {}

    /**
     * Menambah riwayat KGB lewat service append-only tanpa mengubah bentuk model respons.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Employee $employee, array $data, ?Request $request = null): SalaryHistory
    {
        return $this->service->createKgbHistory($employee, $data, $request);
    }
}
