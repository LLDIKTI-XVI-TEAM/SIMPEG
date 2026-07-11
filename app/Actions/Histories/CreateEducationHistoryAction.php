<?php

namespace App\Actions\Histories;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Services\AuditService;
use App\Support\Histories\EducationHistoryPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateEducationHistoryAction
{
    public function __construct(private readonly EducationHistoryPayload $payload) {}

    /**
     * Menambah riwayat pendidikan formal pegawai secara append-only.
     */
    public function execute(Employee $employee, array $data, ?Request $request = null): EducationHistory
    {
        return DB::transaction(function () use ($employee, $data, $request): EducationHistory {
            // Kolom no_ijazah belum nullable di DB pada schema awal;
            // coerce null ke string kosong sebagai safety net sebelum migration dijalankan.
            $data['no_ijazah'] = $data['no_ijazah'] ?? '';

            $history = $employee->educationHistories()->create($data);

            AuditService::log(
                'CREATE',
                'EducationHistory',
                $history->id,
                null,
                $history->toArray(),
                $request,
            );

            return $history->load('jenjang');
        });
    }
}
