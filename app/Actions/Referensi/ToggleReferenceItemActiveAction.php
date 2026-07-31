<?php

namespace App\Actions\Referensi;

use App\Services\AuditService;
use App\Services\Referensi\ReferenceTableCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ToggleReferenceItemActiveAction
{
    /**
     * Menonaktifkan/mengaktifkan item referensi (kebijakan hapus hybrid untuk
     * item yang sudah dipakai data). Dicatat sebagai CONFIG_UPDATE agar jejak
     * perubahan status terpisah dari perubahan data biasa.
     */
    public function execute(Model $item, Request $request): Model
    {
        return DB::transaction(function () use ($item, $request): Model {
            $oldValue = (bool) $item->getAttribute('is_active');

            // Penonaktifan baris data sistem ditolak karena logika aplikasi
            // (EWS, status default pegawai baru) bergantung padanya; pengaktifan
            // kembali tetap diizinkan.
            if ($oldValue) {
                $protectionReason = ReferenceTableCatalog::protectionReason($item);

                if ($protectionReason !== null) {
                    throw ValidationException::withMessages([
                        'referensi' => sprintf('Item tidak dapat dinonaktifkan karena %s.', $protectionReason),
                    ]);
                }
            }

            $item->forceFill(['is_active' => ! $oldValue])->save();

            AuditService::logOrFail(
                'CONFIG_UPDATE',
                class_basename($item),
                $item->getKey(),
                ['is_active' => $oldValue],
                ['is_active' => ! $oldValue],
                $request,
            );
            ReferenceTableCatalog::forgetCachesAfterCommit($item::class);

            return $item;
        });
    }
}
