<?php

namespace App\Actions\Histories;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Services\AuditService;
use App\Support\Histories\EducationHistoryPayload;
use Illuminate\Http\Request;

class DeleteEducationHistoryAction
{
    public function __construct(private readonly EducationHistoryPayload $payload) {}

    /**
     * Menghapus riwayat pendidikan secara permanen setelah memverifikasi kepemilikan data.
     */
    public function execute(Employee $employee, EducationHistory $history, Request $request): void
    {
        abort_unless($history->employee_id === $employee->id, 404);

        $oldValues = $this->payload->response($history);
        $historyId = $history->id;

        $history->delete();

        AuditService::log(
            'DELETE',
            'EducationHistory',
            $historyId,
            $oldValues,
            null,
            $request,
        );
    }
}
