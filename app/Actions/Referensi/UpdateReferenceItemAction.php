<?php

namespace App\Actions\Referensi;

use App\Models\RefStatusPegawai;
use App\Services\AuditService;
use App\Services\Referensi\ReferenceTableCatalog;
use App\Services\Referensi\ReferenceUsageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateReferenceItemAction
{
    public function __construct(private readonly ReferenceUsageService $usage) {}

    /**
     * Memperbarui item reference table dengan jejak audit UPDATE berisi nilai
     * lama dan baru agar perubahan data rujukan tetap dapat ditelusuri.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Model $item, array $data, Request $request): Model
    {
        return DB::transaction(function () use ($item, $data, $request): Model {
            $currentItem = $item;

            if ($item instanceof RefStatusPegawai) {
                // Row lock menyerialkan perubahan identitas/klasifikasi dengan insert FK.
                // Setelah lock didapat, state dan pemakaian dibaca ulang dalam transaksi
                // yang sama agar instance caller yang stale tidak dapat melewati guard.
                $currentItem = RefStatusPegawai::query()
                    ->whereKey($item->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $errors = $this->usage->statusMutationErrors($currentItem, $data);

                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }

            $oldValues = $currentItem->toArray();

            $currentItem->fill($data)->save();

            AuditService::logOrFail('UPDATE', class_basename($currentItem), $currentItem->getKey(), $oldValues, $currentItem->refresh()->toArray(), $request);
            ReferenceTableCatalog::forgetCachesAfterCommit($currentItem::class);

            return $currentItem;
        });
    }
}
