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
        return $employee->educationHistories()
            ->with(['jenjang', 'programStudi'])
            ->orderByDesc('tahun_lulus')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (EducationHistory $h): array => $this->payload->response($h))
            ->values();
    }
}
