<?php

namespace App\Actions\Ews;

use App\Models\EwsConfig;
use App\Services\AuditService;
use App\Services\Ews\EwsConfigCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateEwsConfigAction
{
    /**
     * Menyimpan perubahan konfigurasi EWS. Setiap kunci yang berubah dicatat ke audit log
     * basis data beserta alasan perubahan agar tetap dapat ditelusuri.
     *
     * Penyimpanan dan pencatatannya disatukan dalam satu transaksi supaya tidak ada baris audit
     * yang menerangkan perubahan yang gagal, dan tidak ada perubahan yang berlaku tanpa jejak.
     */
    public function execute(Request $request): void
    {
        $reason = $request->input('reason');

        DB::transaction(function () use ($request, $reason): void {
            foreach (array_keys(EwsConfigCatalog::LABELS) as $key) {
                $oldVal = EwsConfig::getVal($key);
                $newVal = $request->input($key);

                if ((string) $oldVal === (string) $newVal) {
                    continue;
                }

                EwsConfig::setVal($key, $newVal);

                // auditable_id dibiarkan null karena kunci konfigurasi bukan UUID; kunci tersebut
                // disimpan di dalam payload supaya baris audit tetap dapat dicari berdasarkan kunci.
                AuditService::logOrFail(
                    'UPDATE',
                    'EwsConfig',
                    null,
                    ['key' => $key, 'value' => (string) $oldVal],
                    ['key' => $key, 'value' => (string) $newVal, 'reason' => $reason],
                    $request
                );
            }
        });
    }
}
