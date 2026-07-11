<?php

namespace App\Actions\Histories;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Services\AuditService;
use App\Support\Histories\EducationHistoryPayload;
use Illuminate\Http\Request;

class UpdateEducationHistoryAction
{
    public function __construct(private readonly EducationHistoryPayload $payload) {}

    /**
     * Memperbarui riwayat pendidikan setelah memverifikasi kepemilikan data.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Employee $employee, EducationHistory $history, array $data, Request $request): EducationHistory
    {
        abort_unless($history->employee_id === $employee->id, 404);

        $oldValues = $this->payload->response($history);

        // Coerce null ke string kosong sebagai safety net sebelum migration dijalankan.
        $data['no_ijazah'] = $data['no_ijazah'] ?? '';

        $history->update($data);
        $history->load('jenjang');

        AuditService::log(
            'UPDATE',
            'EducationHistory',
            $history->id,
            $oldValues,
            $this->payload->response($history),
            $request,
        );

        return $history;
    }
}
