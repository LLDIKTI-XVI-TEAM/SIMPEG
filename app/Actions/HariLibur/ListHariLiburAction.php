<?php

namespace App\Actions\HariLibur;

use App\Models\RefHariLibur;
use Illuminate\Support\Collection;

class ListHariLiburAction
{
    /**
     * Mengambil hari libur sesuai filter tahun untuk kebutuhan kalender cuti.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(?int $tahun): Collection
    {
        return RefHariLibur::query()
            ->orderBy('tanggal')
            ->when($tahun !== null, fn ($query) => $query->where('tahun', $tahun))
            ->get()
            ->map(fn (RefHariLibur $hariLibur): array => $hariLibur->toApiArray())
            ->values();
    }
}
