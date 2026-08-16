<?php

namespace App\Actions\Histories;

use App\Models\Employee;
use App\Models\SalaryHistory;
use App\Support\Histories\EmployeeHistoryPayload;
use Illuminate\Support\Collection;

class ListKgbHistoriesAction
{
    public function __construct(private readonly EmployeeHistoryPayload $payload) {}

    /**
     * Mengambil riwayat KGB pegawai sesuai urutan TMT terbaru untuk layar riwayat gaji.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(Employee $employee): Collection
    {
        $histories = $employee->salaryHistories()
            ->orderByDesc('tmt_kgb')
            ->orderByDesc('created_at')
            ->get();

        $this->payload->primeAttachmentReferences($histories->pluck('file_sk'));

        return $histories
            ->map(fn (SalaryHistory $history): array => $this->payload->kgb($history, $employee))
            ->values();
    }
}
