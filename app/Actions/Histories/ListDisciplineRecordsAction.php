<?php

namespace App\Actions\Histories;

use App\Models\DisciplineRecord;
use App\Models\Employee;
use App\Support\Histories\DisciplineRecordPayload;
use Illuminate\Support\Collection;

class ListDisciplineRecordsAction
{
    public function __construct(private readonly DisciplineRecordPayload $payload) {}

    /**
     * Mengambil riwayat disiplin dan memproyeksikan field publik sesuai kontrak endpoint saat ini.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(Employee $employee): Collection
    {
        return $employee->disciplineRecords()
            ->orderByDesc('tanggal_mulai')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (DisciplineRecord $record): array => $this->payload->response($record))
            ->values();
    }
}
