<?php

namespace App\Actions\Referensi;

use App\Models\RefUnitKerja;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateUnitKerjaAction
{
    public function __construct(
        private readonly UpdateReferenceItemAction $update,
        private readonly SyncUnitKerjaLevelAction $syncLevel,
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
            $this->update->execute($unit, $data, $request);
            $this->syncLevel->execute($unit->refresh());

            return $unit->refresh();
        });
    }
}
