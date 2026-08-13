<?php

namespace App\Actions\Histories;

use App\Models\Document;
use App\Models\Employee;
use App\Services\EmployeeHistoryService;
use App\Support\Histories\DisciplineRecordPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CreateDisciplineRecordAction
{
    public function __construct(
        private readonly EmployeeHistoryService $service,
        private readonly DisciplineRecordPayload $payload,
    ) {}

    /**
     * Menambah riwayat disiplin lewat service append-only lalu mengembalikan payload publik terbatas.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(Employee $employee, array $data, ?Request $request = null): array
    {
        $record = $this->service->createDisciplineRecord($employee, $data, $request);
        $payload = $this->payload->response($record);

        // UI hanya menerima rute unduh yang tetap melewati pemeriksaan otorisasi server.
        $payload['download_url'] = $record->file_sk
            && Storage::disk(Document::STORAGE_DISK)->exists($record->file_sk)
            ? route('pegawai.history-attachments.download', [
                'employee' => $employee,
                'type' => 'discipline',
                'history' => $record,
            ])
            : null;

        return $payload;
    }
}
