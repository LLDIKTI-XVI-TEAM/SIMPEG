<?php

namespace Database\Seeders;

use App\Models\RefJenisPegawai;
use App\Models\SkRequirement;
use App\Support\Documents\SkCompleteness;
use Illuminate\Database\Seeder;

class SkRequirementSeeder extends Seeder
{
    /**
     * Mengisi default PNS/CPNS secara idempoten. Jenis tanpa default, termasuk
     * PPPK, tidak disentuh agar matriks custom buatan admin tetap terjaga.
     */
    public function run(): void
    {
        foreach (SkCompleteness::DEFAULTS as $typeName => $requiredKeys) {
            $type = RefJenisPegawai::query()->where('nama', $typeName)->first();
            if ($type === null) {
                continue;
            }

            foreach (SkCompleteness::poolKeys() as $skKey) {
                SkRequirement::query()->firstOrCreate(
                    ['jenis_pegawai_id' => $type->id, 'sk_key' => $skKey],
                    ['is_wajib' => in_array($skKey, $requiredKeys, true)],
                );
            }
        }
    }
}
