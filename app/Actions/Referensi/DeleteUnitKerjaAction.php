<?php

namespace App\Actions\Referensi;

use App\Models\RefUnitKerja;
use App\Services\Referensi\UnitKerjaHierarchyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeleteUnitKerjaAction
{
    public function __construct(
        private readonly DeleteReferenceItemAction $delete,
        private readonly UnitKerjaHierarchyService $hierarchy,
    ) {}

    /**
     * Menghapus unit di bawah mutex yang sama dengan operasi mutasi hierarki lain agar
     * child baru tidak dapat ditambahkan setelah pemeriksaan pemakaian selesai.
     */
    public function execute(RefUnitKerja $unit, Request $request): void
    {
        DB::transaction(function () use ($unit, $request): void {
            $this->hierarchy->lockForMutation();
            $unit->refresh();
            $this->delete->execute($unit, $request);
        });
    }
}
