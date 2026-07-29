<?php

namespace App\Actions\Referensi;

use App\Models\RefUnitKerja;
use App\Services\Referensi\UnitKerjaHierarchyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateUnitKerjaAction
{
    public function __construct(
        private readonly CreateReferenceItemAction $create,
        private readonly UnitKerjaHierarchyService $hierarchy,
    ) {}

    /**
     * Membuat unit setelah mutex hierarki diperoleh agar parent tidak dapat
     * dinonaktifkan atau dipindahkan di antara pemeriksaan dan penyimpanan.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, Request $request): RefUnitKerja
    {
        return DB::transaction(function () use ($data, $request): RefUnitKerja {
            $this->hierarchy->lockForMutation();
            $this->hierarchy->ensureNameAvailable($data['nama']);

            $parentId = is_string($data['parent_id'] ?? null)
                ? $data['parent_id']
                : null;

            if ($parentId !== null) {
                $this->hierarchy->ensureParentAllowed($parentId, null, false);
            }

            $data['level'] = $this->hierarchy->levelFromParent($parentId);

            /** @var RefUnitKerja $unit */
            $unit = $this->create->execute(RefUnitKerja::class, $data, $request);

            return $unit;
        });
    }
}
