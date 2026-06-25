<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Services\EmployeeHistoryService;
use App\Support\Histories\DisciplineRecordPayload;
use Illuminate\Http\Request;

class CreateDisciplineRecordAction
{
    public function __construct(
        private readonly EmployeeHistoryService $service,
        private readonly DisciplineRecordPayload $payload,
    ) {}

    /**
     * Menambah riwayat disiplin lewat service append-only lalu mengembalikan payload publik terbatas.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function execute(Employee $employee, array $data, ?Request $request = null): array
    {
        return $this->payload->response(
            $this->service->createDisciplineRecord($employee, $data, $request),
        );
    }
}
