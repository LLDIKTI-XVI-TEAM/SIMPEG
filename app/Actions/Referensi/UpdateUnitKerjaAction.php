<?php

namespace App\Actions\Referensi;

use App\Models\RefUnitKerja;
use App\Services\Referensi\UnitKerjaHierarchyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateUnitKerjaAction
{
    public function __construct(
        private readonly UpdateReferenceItemAction $update,
        private readonly SyncUnitKerjaLevelAction $syncLevel,
        private readonly UnitKerjaHierarchyService $hierarchy,
    ) {}

    /**
     * Memperbarui unit kerja sekaligus menyelaraskan kedalaman sub-unitnya.
     * Keduanya dijalankan dalam satu transaksi karena pemindahan induk yang
     * tersimpan tanpa penyelarasan level akan meninggalkan pohon yang tampil
     * pada kedalaman salah, dan kegagalan di tengah subtree tidak boleh
     * menyisakan sebagian unit dengan level lama.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(RefUnitKerja $unit, array $data, Request $request): RefUnitKerja
    {
        return DB::transaction(function () use ($unit, $data, $request): RefUnitKerja {
            $this->hierarchy->lockForMutation();

            $unit = RefUnitKerja::query()->findOrFail($unit->getKey());
            $this->hierarchy->ensureNameAvailable($data['nama'], $unit->id);

            $parentId = array_key_exists('parent_id', $data)
                ? (is_string($data['parent_id']) ? $data['parent_id'] : null)
                : $unit->parent_id;
            $allowInactiveChain = ! $unit->is_active
                && $unit->parent_id === $parentId;

            if ($parentId !== null) {
                $this->hierarchy->ensureParentAllowed(
                    $parentId,
                    $unit->id,
                    $allowInactiveChain,
                );
            }

            $data['level'] = $this->hierarchy->levelFromParent($parentId);
            $this->update->execute($unit, $data, $request);
            $this->syncLevel->execute($unit);

            return $unit->refresh();
        });
    }
}
