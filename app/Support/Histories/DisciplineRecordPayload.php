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
        $payload = Arr::only($record->toArray(), [
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

        // Date-only fields must remain calendar dates in API responses. Eloquent's
        // default JSON serialization may turn them into UTC timestamps, which can
        // shift the displayed day for time zones ahead of UTC.
        $payload['tanggal_mulai'] = $record->tanggal_mulai?->format('Y-m-d');
        $payload['tanggal_berakhir'] = $record->tanggal_berakhir?->format('Y-m-d');
        $payload['tanggal_sk'] = $record->tanggal_sk?->format('Y-m-d');

        return $payload;
    }
}
