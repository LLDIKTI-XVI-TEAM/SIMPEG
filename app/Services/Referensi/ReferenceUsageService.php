<?php

namespace App\Services\Referensi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReferenceUsageService
{
    /**
     * Menghitung pemakaian item referensi per tabel pemakai. Hanya entri dengan
     * jumlah lebih dari nol yang dikembalikan agar bisa langsung dipakai sebagai
     * pesan penolakan penghapusan.
     *
     * @return array<string, int> label pemakai => jumlah baris
     */
    public function usageDetail(Model $item): array
    {
        $detail = [];

        foreach (ReferenceTableCatalog::usageReferences($item::class) as $reference) {
            $count = DB::table($reference['table'])
                ->where($reference['column'], $item->getKey())
                ->count();

            if ($count > 0) {
                $detail[$reference['label']] = $count;
            }
        }

        return $detail;
    }

    public function isInUse(Model $item): bool
    {
        return $this->usageDetail($item) !== [];
    }
}
