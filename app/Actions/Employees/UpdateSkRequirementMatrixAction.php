<?php

namespace App\Actions\Employees;

use App\Models\RefJenisPegawai;
use App\Models\SkRequirement;
use App\Services\AuditService;
use App\Support\Documents\SkCompleteness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateSkRequirementMatrixAction
{
    /**
     * Menyimpan seluruh pasangan jenis dan kategori secara atomik. Penguncian
     * row jenis pegawai mencegah dua penyimpanan paralel saling menimpa diam-diam.
     *
     * @param  array<string, list<string>>  $matrix
     * @return array<string, list<string>>
     */
    public function execute(array $matrix, Request $request): array
    {
        $normalized = $this->normalize($matrix);
        $typeIds = array_keys($normalized);

        return DB::transaction(function () use ($normalized, $typeIds, $request): array {
            RefJenisPegawai::query()
                ->whereIn('id', $typeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $old = $this->activeMatrix($typeIds);

            foreach ($normalized as $typeId => $requiredKeys) {
                foreach (SkCompleteness::poolKeys() as $skKey) {
                    SkRequirement::query()->updateOrCreate(
                        ['jenis_pegawai_id' => $typeId, 'sk_key' => $skKey],
                        ['is_wajib' => in_array($skKey, $requiredKeys, true)],
                    );
                }
            }

            $new = $this->activeMatrix($typeIds);

            if ($old !== $new) {
                AuditService::logOrFail(
                    'CONFIG_UPDATE',
                    'SkRequirement',
                    null,
                    ['matrix' => $old],
                    ['matrix' => $new],
                    $request,
                );
            }

            return $new;
        });
    }

    /**
     * @param  list<string>  $typeIds
     * @return array<string, list<string>>
     */
    private function activeMatrix(array $typeIds): array
    {
        $matrix = array_fill_keys($typeIds, []);

        SkRequirement::query()
            ->whereIn('jenis_pegawai_id', $typeIds)
            ->where('is_wajib', true)
            ->orderBy('jenis_pegawai_id')
            ->orderBy('sk_key')
            ->get(['jenis_pegawai_id', 'sk_key'])
            ->each(function (SkRequirement $requirement) use (&$matrix): void {
                $matrix[$requirement->jenis_pegawai_id][] = $requirement->sk_key;
            });

        ksort($matrix);

        return $matrix;
    }

    /**
     * @param  array<string, list<string>>  $matrix
     * @return array<string, list<string>>
     */
    private function normalize(array $matrix): array
    {
        foreach ($matrix as &$requiredKeys) {
            $requiredKeys = array_values(array_unique($requiredKeys));
            sort($requiredKeys);
        }
        unset($requiredKeys);
        ksort($matrix);

        return $matrix;
    }
}
