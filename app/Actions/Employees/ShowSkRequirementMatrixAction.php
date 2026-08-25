<?php

namespace App\Actions\Employees;

use App\Models\RefJenisPegawai;
use App\Models\SkRequirement;
use App\Support\Documents\SkCompleteness;

class ShowSkRequirementMatrixAction
{
    /**
     * Menyusun payload kecil untuk modal matriks tanpa query dari Blade.
     *
     * @return array{
     *     skPool: array<string, string>,
     *     current: array<string, list<string>>,
     *     namesByType: array<string, string>
     * }
     */
    public function execute(): array
    {
        $types = RefJenisPegawai::query()
            ->orderBy('nama')
            ->get(['id', 'nama']);

        $active = SkRequirement::query()
            ->where('is_wajib', true)
            ->orderBy('sk_key')
            ->get(['jenis_pegawai_id', 'sk_key'])
            ->groupBy('jenis_pegawai_id')
            ->map(fn ($rows): array => $rows->pluck('sk_key')->values()->all())
            ->all();

        // Setiap jenis selalu memiliki array draft agar checkbox Alpine dapat
        // dikelola tanpa membuat konfigurasi bawaan yang belum ditetapkan.
        $current = $types
            ->mapWithKeys(fn (RefJenisPegawai $type): array => [
                $type->id => $active[$type->id] ?? [],
            ])
            ->all();

        return [
            'skPool' => SkCompleteness::pool(),
            'current' => $current,
            'namesByType' => $types->pluck('nama', 'id')->all(),
        ];
    }
}
