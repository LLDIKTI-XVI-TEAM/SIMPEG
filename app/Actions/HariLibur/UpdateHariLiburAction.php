<?php

namespace App\Actions\HariLibur;

use App\Models\RefHariLibur;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UpdateHariLiburAction
{
    /**
     * Memperbarui referensi hari libur dan menyimpan nilai lama untuk audit.
     *
     * @param  array{tanggal: string, nama: string, tipe: string}  $data
     */
    public function execute(RefHariLibur $hariLibur, array $data, Request $request): RefHariLibur
    {
        $oldValues = $hariLibur->toApiArray();
        $tanggalCarbon = Carbon::parse($data['tanggal']);

        $hariLibur->update([
            'tanggal' => $tanggalCarbon->format('Y-m-d'),
            'nama' => $data['nama'],
            'tahun' => (int) $tanggalCarbon->format('Y'),
            'is_cuti_bersama' => $data['tipe'] === 'cuti_bersama',
        ]);

        AuditService::log('UPDATE', 'RefHariLibur', $hariLibur->id, $oldValues, $hariLibur->toApiArray(), $request);

        return $hariLibur;
    }
}
