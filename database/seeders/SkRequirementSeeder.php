<?php

namespace Database\Seeders;

use App\Models\RefJenisPegawai;
use App\Models\SkRequirement;
use App\Support\Documents\SkCompleteness;
use Illuminate\Database\Seeder;

/**
 * Mengisi default matriks "SK wajib per jenis pegawai":
 * PNS/CPNS wajib 4 SK (pengangkatan, pangkat, jabatan, kgb);
 * PPPK wajib 2 SK (pengangkatan, kgb).
 *
 * Setiap kombinasi (jenis, sk) dibuat satu baris. firstOrCreate dipakai agar
 * is_wajib yang sudah disesuaikan super admin tidak ditimpa oleh seed berulang.
 */
class SkRequirementSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SkCompleteness::DEFAULTS as $nama => $wajibKeys) {
            $jenis = RefJenisPegawai::query()->where('nama', $nama)->first();
            if ($jenis === null) {
                continue;
            }

            foreach (SkCompleteness::poolKeys() as $skKey) {
                SkRequirement::firstOrCreate(
                    [
                        'jenis_pegawai_id' => $jenis->id,
                        'sk_key' => $skKey,
                    ],
                    [
                        'is_wajib' => in_array($skKey, $wajibKeys, true),
                    ],
                );
            }
        }
    }
}
