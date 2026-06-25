<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\RankHistory;
use App\Services\EmployeeHistoryService;
use Illuminate\Http\Request;

class CreateRankHistoryAction
{
    public function __construct(private readonly EmployeeHistoryService $service) {}

    /**
     * Menambah riwayat pangkat lewat service append-only dan memuat referensi golongan untuk respons.
     *
     * @param array<string, mixed> $data
     */
    public function execute(Employee $employee, array $data, ?Request $request = null): RankHistory
    {
        return $this->service
            ->createRankHistory($employee, $data, $request)
            ->load('golongan');
    }
}
