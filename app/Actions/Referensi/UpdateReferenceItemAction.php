<?php

namespace App\Actions\Referensi;

use App\Services\AuditService;
use App\Services\Referensi\ReferenceTableCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateReferenceItemAction
{
    /**
     * Memperbarui item reference table dengan jejak audit UPDATE berisi nilai
     * lama dan baru agar perubahan data rujukan tetap dapat ditelusuri.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Model $item, array $data, Request $request): Model
    {
        return DB::transaction(function () use ($item, $data, $request): Model {
            $oldValues = $item->toArray();

            $item->fill($data)->save();

            AuditService::logOrFail('UPDATE', class_basename($item), $item->getKey(), $oldValues, $item->refresh()->toArray(), $request);
            ReferenceTableCatalog::forgetCachesAfterCommit($item::class);

            return $item;
        });
    }
}
