<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\PositionHistory;
use App\Services\EmployeeHistoryService;
use Illuminate\Http\Request;

class CreatePositionHistoryAction
{
    public function __construct(private readonly EmployeeHistoryService $service) {}

    /**
     * Menambah riwayat jabatan lewat service append-only dan memuat referensi jabatan untuk respons.
     *
     * @param array<string, mixed> $data
     */
    public function execute(Employee $employee, array $data, ?Request $request = null): PositionHistory
    {
        return $this->service
            ->createPositionHistory($employee, $data, $request)
            ->load(['jenisJabatan', 'eselon', 'unitKerja']);
    }
}
