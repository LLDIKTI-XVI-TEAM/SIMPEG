<?php

namespace App\Actions\Employees;

use App\Http\Requests\UpdateSkRequirementMatrixRequest;
use App\Models\RefJenisPegawai;
use App\Models\SkRequirement;
use App\Services\AuditService;
use App\Support\Documents\SkCompleteness;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan matriks "SK wajib per jenis pegawai".
 *
 * Untuk tiap pasangan (jenis, sk) dilakukan upsert is_wajib. Jenis yang tidak
 * dikirim halaman (mis. dihapus) tidak disentuh; jenis yang ada dalam form
 * dicentang atau tidak dicentang eksplisit.
 */
class UpdateSkRequirementMatrixAction
{
    public function execute(UpdateSkRequirementMatrixRequest $request): void
    {
        $matrix = $request->validated()['matrix'] ?? [];
        $allowedKeys = SkCompleteness::poolKeys();

        $validTypeIds = RefJenisPegawai::query()
            ->whereIn('id', array_keys($matrix))
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($matrix, $allowedKeys, $validTypeIds, $request): void {
            $old = SkRequirement::query()
                ->where('is_wajib', true)
                ->whereIn('jenis_pegawai_id', $validTypeIds)
                ->get(['jenis_pegawai_id', 'sk_key'])
                ->map(fn (SkRequirement $r): array => ['jenis_pegawai_id' => $r->jenis_pegawai_id, 'sk_key' => $r->sk_key])
                ->all();

            $new = [];
            foreach ($validTypeIds as $typeId) {
                $checked = array_values(array_intersect($matrix[$typeId] ?? [], $allowedKeys));

                foreach ($allowedKeys as $skKey) {
                    $isWajib = in_array($skKey, $checked, true);

                    SkRequirement::updateOrCreate(
                        ['jenis_pegawai_id' => $typeId, 'sk_key' => $skKey],
                        ['is_wajib' => $isWajib],
                    );

                    if ($isWajib) {
                        $new[] = ['jenis_pegawai_id' => $typeId, 'sk_key' => $skKey];
                    }
                }
            }

            if ($old !== $new) {
                AuditService::logOrFail(
                    'UPDATE',
                    'SkRequirement',
                    null,
                    ['matrix' => $old],
                    ['matrix' => $new, 'reason' => $request->input('reason')],
                    $request,
                );
            }
        });
    }
}
