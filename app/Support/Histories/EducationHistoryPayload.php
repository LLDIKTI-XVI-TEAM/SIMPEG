<?php

namespace App\Support\Histories;

use App\Models\EducationHistory;

class EducationHistoryPayload
{
    /**
     * Memproyeksikan field publik riwayat pendidikan untuk kontrak respons API.
     *
     * @return array<string, mixed>
     */
    public function response(EducationHistory $history): array
    {
        return [
            'id' => $history->id,
            'employee_id' => $history->employee_id,
            'jenjang_id' => $history->jenjang_id,
            'tingkat' => $history->jenjang?->nama ?? '-',
            'nama_institusi' => $history->nama_institusi,
            'jurusan' => $history->jurusan,
            'tahun_lulus' => $history->tahun_lulus,
            'no_ijazah' => $history->no_ijazah,
        ];
    }
}
