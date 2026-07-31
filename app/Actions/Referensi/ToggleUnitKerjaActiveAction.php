<?php

namespace App\Actions\Referensi;

use App\Models\RefUnitKerja;
use App\Services\Referensi\UnitKerjaHierarchyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ToggleUnitKerjaActiveAction
{
    public function __construct(
        private readonly ToggleReferenceItemActiveAction $toggle,
        private readonly UnitKerjaHierarchyService $hierarchy,
    ) {}

    /**
     * Menjaga status cabang tetap konsisten: unit hanya dapat dinonaktifkan
     * setelah seluruh anaknya nonaktif, dan hanya dapat diaktifkan setelah
     * seluruh rantai induknya aktif.
     *
     * @throws ValidationException bila urutan perubahan status melanggar hierarki
     */
    public function execute(RefUnitKerja $unit, Request $request): void
    {
        DB::transaction(function () use ($unit, $request): void {
            $this->hierarchy->lockForMutation();
            $unit->refresh();

            if ($unit->is_active) {
                $this->ensureNoActiveChildren($unit);
            } else {
                $this->ensureActiveParentChain($unit);
            }

            $this->toggle->execute($unit, $request);
        });
    }

    private function ensureNoActiveChildren(RefUnitKerja $unit): void
    {
        $anakAktif = RefUnitKerja::query()
            ->where('parent_id', $unit->getKey())
            ->where('is_active', true)
            ->count();

        if ($anakAktif > 0) {
            throw ValidationException::withMessages([
                'referensi' => sprintf(
                    'Unit tidak dapat dinonaktifkan karena masih memiliki %d sub-unit aktif. Nonaktifkan sub-unit tersebut lebih dulu.',
                    $anakAktif,
                ),
            ]);
        }
    }

    private function ensureActiveParentChain(RefUnitKerja $unit): void
    {
        $visited = [];
        $parentId = $unit->parent_id;

        while ($parentId !== null) {
            if (isset($visited[$parentId])) {
                throw ValidationException::withMessages([
                    'referensi' => 'Unit tidak dapat diaktifkan karena rantai induknya membentuk lingkaran.',
                ]);
            }

            $visited[$parentId] = true;

            /** @var RefUnitKerja|null $parent */
            $parent = RefUnitKerja::query()->find($parentId);

            if ($parent === null || ! $parent->is_active) {
                throw ValidationException::withMessages([
                    'referensi' => 'Unit tidak dapat diaktifkan sebelum seluruh unit induknya aktif.',
                ]);
            }

            $parentId = $parent->parent_id;
        }
    }
}
