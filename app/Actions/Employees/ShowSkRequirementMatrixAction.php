<?php

namespace App\Actions\Employees;

use App\Models\RefJenisPegawai;
use App\Models\SkRequirement;
use App\Support\Documents\SkCompleteness;
use Illuminate\Database\Eloquent\Collection;

/**
 * Menyusun data halaman konfigurasi "SK wajib per jenis pegawai" untuk super admin.
 */
class ShowSkRequirementMatrixAction
{
    /**
     * @return array{
     *     jenisList: Collection<int, RefJenisPegawai>,
     *     skPool: array<string, string>,
     *     current: array<string, list<string>>,
     *     namesByType: array<string, string>,
     *     defaults: array<string, list<string>>
     * }
     */
    public function execute(): array
    {
        $jenisList = RefJenisPegawai::query()->orderBy('nama')->get(['id', 'nama']);

        $current = SkRequirement::query()
            ->where('is_wajib', true)
            ->get(['jenis_pegawai_id', 'sk_key'])
            ->groupBy('jenis_pegawai_id')
            ->map(fn ($rows): array => $rows->pluck('sk_key')->all())
            ->all();

        return [
            'jenisList' => $jenisList,
            'skPool' => SkCompleteness::pool(),
            'current' => $current,
            'namesByType' => $jenisList->pluck('nama', 'id')->all(),
            'defaults' => SkCompleteness::DEFAULTS,
        ];
    }
}
