<?php

namespace App\Actions\Referensi;

use App\Services\AuditService;
use App\Services\Referensi\ReferenceTableCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
        $oldValues = $item->toArray();

        $item->fill($data)->save();

        AuditService::log('UPDATE', class_basename($item), $item->getKey(), $oldValues, $item->refresh()->toArray(), $request);
        $this->forgetCaches($item::class);

        return $item;
    }

    private function forgetCaches(string $modelClass): void
    {
        foreach (ReferenceTableCatalog::cacheKeys($modelClass) as $cacheKey) {
            Cache::forget($cacheKey);
        }
    }
}
