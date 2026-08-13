<?php

namespace App\Actions\Histories;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\RefProgramStudi;
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
        // Jangan jadikan field snapshot legacy sebagai sumber nilai dari client.
        unset($data['jurusan']);
        if (array_key_exists('program_studi_id', $data)) {
            $data['jurusan'] = $data['program_studi_id']
                ? RefProgramStudi::find($data['program_studi_id'])?->nama
                : null;
        }

        $history->update($data);
        $history->load(['jenjang', 'programStudi']);

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
