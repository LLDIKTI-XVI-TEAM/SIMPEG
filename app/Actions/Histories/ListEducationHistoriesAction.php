<?php

namespace App\Actions\Histories;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Support\Histories\EducationHistoryPayload;
use Illuminate\Support\Collection;

class ListEducationHistoriesAction
{
    public function __construct(private readonly EducationHistoryPayload $payload) {}

    /**
     * Mengambil seluruh riwayat pendidikan pegawai, diurutkan dari tahun lulus terbaru.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(Employee $employee): Collection
    {
        $histories = $employee->educationHistories()
            ->with('jenjang')
            ->orderByDesc('tahun_lulus')
            ->orderByDesc('created_at')
            ->get();
        $this->payload->primeAttachmentReferences($histories->pluck('file_ijazah'));

        return $histories
            ->map(fn (EducationHistory $h): array => $this->payload->response($h, $employee))
            ->values();
    }
}
