<?php

namespace App\Support\Histories;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\RefJenjangPendidikan;
use App\Services\Employees\EmployeeHistoryAttachmentService;

class EducationHistoryPayload
{
    public function __construct(private readonly EmployeeHistoryAttachmentService $attachments) {}

    /** @param iterable<int, mixed> $paths */
    public function primeAttachmentReferences(iterable $paths): void
    {
        $this->attachments->primeDocumentReferences($paths);
    }

    /**
     * Memproyeksikan field publik riwayat pendidikan untuk kontrak respons API.
     *
     * @return array<string, mixed>
     */
    public function response(EducationHistory $history, ?Employee $employee = null): array
    {
        /** @var RefJenjangPendidikan|null $jenjang */
        $jenjang = $history->jenjang;

        return [
            'id' => $history->id,
            'employee_id' => $history->employee_id,
            'jenjang_id' => $history->jenjang_id,
            'tingkat' => $jenjang?->nama ?? '-',
            'nama_institusi' => $history->nama_institusi,
            'jurusan' => $history->jurusan,
            'tahun_lulus' => $history->tahun_lulus,
            'no_ijazah' => $history->no_ijazah,
            'download_url' => $employee === null
                ? null
                : $this->attachments->downloadUrl(
                    $employee,
                    'education',
                    $history,
                    'pegawai.history-attachments.download',
                ),
        ];
    }
}
