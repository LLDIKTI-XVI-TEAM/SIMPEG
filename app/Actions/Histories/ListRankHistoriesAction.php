<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\RankHistory;
use App\Support\Histories\EmployeeHistoryPayload;
use Illuminate\Support\Collection;

class ListRankHistoriesAction
{
    public function __construct(private readonly EmployeeHistoryPayload $payload) {}

    /**
     * Mengambil riwayat pangkat pegawai sesuai urutan tampilan mutasi terbaru.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(Employee $employee): Collection
    {
        $histories = $employee->rankHistories()
            ->with('golongan')
            ->orderByDesc('tmt_pangkat')
            ->orderByDesc('created_at')
            ->get();

        $this->payload->primeAttachmentReferences($histories->pluck('file_sk'));

        return $histories
            ->map(fn (RankHistory $history): array => $this->payload->rank($history, $employee))
            ->values();
    }
}
