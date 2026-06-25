<?php

namespace App\Support\Histories;

use App\Models\DisciplineRecord;
use Illuminate\Support\Arr;

class DisciplineRecordPayload
{
    /**
     * Membatasi payload disiplin agar kontrak respons tetap sama dan tidak membuka field internal.
     *
     * @return array<string, mixed>
     */
    public function response(DisciplineRecord $record): array
    {
        return Arr::only($record->toArray(), [
            'id',
            'employee_id',
            'jenis_hukuman',
            'deskripsi',
            'tanggal_mulai',
            'tanggal_berakhir',
            'no_sk',
            'tanggal_sk',
            'file_sk',
            'is_active',
            'created_at',
        ]);
    }
}
