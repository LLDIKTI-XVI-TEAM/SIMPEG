<?php

namespace App\Actions\HariLibur;

use App\Models\RefHariLibur;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CreateHariLiburAction
{
    /**
     * Membuat referensi hari libur dan mencatat audit untuk perubahan kalender cuti.
     *
     * @param  array{tanggal: string, nama: string, tipe: string}  $data
     */
    public function execute(array $data, Request $request): RefHariLibur
    {
        $tanggalCarbon = Carbon::parse($data['tanggal']);

        $hariLibur = RefHariLibur::create([
            'tanggal' => $tanggalCarbon->format('Y-m-d'),
            'nama' => $data['nama'],
            'tahun' => (int) $tanggalCarbon->format('Y'),
            'is_cuti_bersama' => $data['tipe'] === 'cuti_bersama',
        ]);

        AuditService::log('CREATE', 'RefHariLibur', $hariLibur->id, null, $hariLibur->toApiArray(), $request);

        return $hariLibur;
    }
}
