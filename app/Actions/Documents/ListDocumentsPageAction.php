<?php

namespace App\Actions\Documents;

use App\Models\User;
use App\Services\Employees\KepalaBagianScopeService;
use App\Support\Documents\DocumentCategory;
use Illuminate\Support\Collection;

class ListDocumentsPageAction
{
    /**
     * @return array{categoryLabels: array<string, string>, bawahans: Collection<int, mixed>, isKepalaBagian: bool, isPegawai: bool}
     */
    public function execute(?User $viewer = null): array
    {
        $isKepalaBagian = $viewer !== null && $viewer->getEffectiveRole() === 'kepala_bagian';
        $isPegawai = $viewer !== null && $viewer->getEffectiveRole() === 'pegawai';
        $bawahans = collect();
        if ($isKepalaBagian && $viewer !== null) {
            $bawahans = app(KepalaBagianScopeService::class)
                ->directReports($viewer)
                ->select(['employees.id', 'employees.nama_lengkap', 'employees.nip'])
                ->orderBy('employees.nama_lengkap')
                ->get();
        }

        return [
            'categoryLabels' => DocumentCategory::labels(),
            'bawahans' => $bawahans,
            'isKepalaBagian' => $isKepalaBagian,
            'isPegawai' => $isPegawai,
        ];
    }
}
