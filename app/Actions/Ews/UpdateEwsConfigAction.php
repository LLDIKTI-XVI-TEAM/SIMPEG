<?php

namespace App\Actions\Ews;

use App\Models\EwsConfig;
use App\Services\AuditService;
use App\Services\Ews\EwsConfigCatalog;
use Illuminate\Http\Request;

class UpdateEwsConfigAction
{
    /**
     * Menyimpan perubahan konfigurasi EWS. Setiap kunci yang berubah dicatat ke
     * audit log database beserta alasan perubahan agar tetap dapat ditelusuri,
     * lalu dicerminkan ke session untuk kompatibilitas tampilan audit lama.
     */
    public function execute(Request $request): void
    {
        $dynamicLogs = session('dynamic_audit_logs', []);
        $operator = $request->user()?->name ?? 'super_admin';
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $reason = $request->input('reason');
        $changed = false;

        foreach (EwsConfigCatalog::LABELS as $key => $label) {
            $oldVal = EwsConfig::getVal($key);
            $newVal = $request->input($key);

            if ((string) $oldVal !== (string) $newVal) {
                // Audit log database adalah jejak permanen; auditable_id null karena
                // kunci konfigurasi bukan UUID.
                AuditService::log(
                    'UPDATE',
                    'EwsConfig',
                    null,
                    ['key' => $key, 'value' => (string) $oldVal],
                    ['key' => $key, 'value' => (string) $newVal, 'reason' => $reason],
                    $request
                );

                $newId = count($dynamicLogs) + 1;
                $dynamicLogs[] = [
                    'id' => $newId,
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                    'operator' => $operator,
                    'event' => 'UPDATE_EWS_CONFIG',
                    'kategori' => 'konfigurasi_sistem',
                    'modul' => 'EwsConfig',
                    'record_id' => $label,
                    'ip_address' => $ip,
                    'user_agent' => $userAgent,
                    'old_values' => ['value' => (string) $oldVal],
                    'new_values' => ['value' => (string) $newVal, 'reason' => $reason],
                ];
                EwsConfig::setVal($key, $newVal);
                $changed = true;
            }
        }

        if ($changed) {
            session(['dynamic_audit_logs' => $dynamicLogs]);
        }
    }
}
