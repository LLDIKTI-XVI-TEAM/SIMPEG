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

    /**
     * Menghitung jumlah pemakaian per item untuk satu reference table secara
     * agregat: satu query GROUP BY per tabel pemakai, bukan satu query count
     * per baris, supaya halaman daftar data master tetap ringan.
     *
     * @return array<string, int> id item => total baris pemakai
     */
    public function usageCountMap(string $modelClass): array
    {
        $map = [];

        foreach (ReferenceTableCatalog::usageReferences($modelClass) as $reference) {
            $counts = DB::table($reference['table'])
                ->select($reference['column'], DB::raw('count(*) as total'))
                ->whereNotNull($reference['column'])
                ->groupBy($reference['column'])
                ->pluck('total', $reference['column']);

            foreach ($counts as $id => $total) {
                $map[(string) $id] = ($map[(string) $id] ?? 0) + (int) $total;
            }
        }

        return $map;
    }
}
