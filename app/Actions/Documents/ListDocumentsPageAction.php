<?php

namespace App\Actions\Documents;

use App\Models\Employee;
use App\Support\Documents\DocumentCategory;

class ListDocumentsPageAction
{
    public function execute(): array
    {
        $pegawaiList = Employee::orderBy('nama_lengkap')->get(['id', 'nama_lengkap', 'nip']);

        return [
            'pegawaiList' => $pegawaiList,
            'pegawaiOptions' => $pegawaiList->map(fn (Employee $pegawai) => [
                'id' => $pegawai->id,
                'label' => $pegawai->nama_lengkap.' (NIP. '.$pegawai->nip.')',
            ])->values(),
            'categoryLabels' => DocumentCategory::labels(),
        ];
    }
}
