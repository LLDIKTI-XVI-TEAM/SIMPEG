<?php

namespace App\Support\Histories;

use App\Models\EducationHistory;
use App\Models\RefJenjangPendidikan;

class EducationHistoryPayload
{
    /**
     * Memproyeksikan field publik riwayat pendidikan untuk kontrak respons API.
     *
     * @return array<string, mixed>
     */
    public function response(EducationHistory $history): array
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
        ];
    }
}
