<?php

namespace App\Actions\Referensi;

use App\Services\AuditService;
use App\Services\Referensi\ReferenceTableCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CreateReferenceItemAction
{
    /**
     * Membuat item reference table baru dengan jejak audit CREATE.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $data
     */
    public function execute(string $modelClass, array $data, Request $request): Model
    {
        $item = $modelClass::create($data);

        AuditService::log('CREATE', class_basename($modelClass), $item->getKey(), null, $item->toArray(), $request);
        $this->forgetCaches($modelClass);

        return $item;
    }

    private function forgetCaches(string $modelClass): void
    {
        foreach (ReferenceTableCatalog::cacheKeys($modelClass) as $cacheKey) {
            Cache::forget($cacheKey);
        }
    }
}
