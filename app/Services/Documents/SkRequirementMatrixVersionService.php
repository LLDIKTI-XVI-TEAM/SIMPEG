<?php

namespace App\Services\Documents;

use App\Models\SkRequirement;

final class SkRequirementMatrixVersionService
{
    /**
     * Membuat revision deterministik dari pasangan matriks yang aktif.
     * Revision berubah hanya ketika sumber penilaian kelengkapan berubah.
     */
    public function current(): string
    {
        $serializedMatrix = SkRequirement::query()
            ->where('is_wajib', true)
            ->orderBy('jenis_pegawai_id')
            ->orderBy('sk_key')
            ->get(['jenis_pegawai_id', 'sk_key'])
            ->map(fn (SkRequirement $requirement): string => $requirement->jenis_pegawai_id.':'.$requirement->sk_key)
            ->implode("\n");

        return hash('sha256', $serializedMatrix);
    }
}
