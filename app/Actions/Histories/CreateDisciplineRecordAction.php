<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Services\EmployeeHistoryService;
use App\Services\Employees\EmployeeHistoryAttachmentService;
use App\Support\Histories\DisciplineRecordPayload;
use Illuminate\Http\Request;

class CreateDisciplineRecordAction
{
    public function __construct(
        private readonly EmployeeHistoryService $service,
        private readonly DisciplineRecordPayload $payload,
        private readonly EmployeeHistoryAttachmentService $attachments,
    ) {}

    /**
     * Menambah riwayat disiplin lewat service append-only lalu mengembalikan payload publik terbatas.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(Employee $employee, array $data, ?Request $request = null): array
    {
        $record = $this->service->createDisciplineRecord($employee, $data, $request);
        $payload = $this->payload->response($record);

        // Payload memakai resolver yang sama dengan endpoint agar URL yang pasti ditolak tidak pernah dipublikasikan.
        $payload['download_url'] = $this->attachments->downloadUrl(
            $employee,
            'discipline',
            $record,
            'pegawai.history-attachments.download',
        );

        return $payload;
    }
}
