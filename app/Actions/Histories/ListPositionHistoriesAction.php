<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\PositionHistory;
use App\Support\Histories\EmployeeHistoryPayload;
use Illuminate\Support\Collection;

class ListPositionHistoriesAction
{
    public function __construct(private readonly EmployeeHistoryPayload $payload) {}

    /**
     * Mengambil riwayat jabatan pegawai beserta referensi jabatan, eselon, dan unit kerja.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(Employee $employee): Collection
    {
        $histories = $employee->positionHistories()
            ->with(['jabatan', 'jenisJabatan', 'eselon', 'unitKerja'])
            ->orderByDesc('tmt_jabatan')
            ->orderByDesc('created_at')
            ->get();

        $this->payload->primeAttachmentReferences($histories->pluck('file_sk'));

        return $histories
            ->map(fn (PositionHistory $history): array => $this->payload->position($history, $employee))
            ->values();
    }
}
