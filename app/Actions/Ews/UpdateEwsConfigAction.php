<?php

namespace App\Actions\Ews;

use App\Models\EmployeeMilestone;
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
     *
     * US-5.5: Jika konfigurasi yang memengaruhi kalkulasi milestone berubah
     * (pangkat_required_years, kgb_required_years), invalidasi milestone terkait
     * agar scheduler menggunakan konfigurasi terbaru.
     */
    public function execute(Request $request): void
    {
        $dynamicLogs = session('dynamic_audit_logs', []);
        $operator = $request->user()?->name ?? 'super_admin';
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $reason = $request->input('reason');
        $changed = false;

        // Konfigurasi yang memengaruhi kalkulasi milestone
        $milestoneImpactingKeys = [
            'pangkat_required_years' => EmployeeMilestone::TYPE_KENAIKAN_PANGKAT,
            'kgb_required_years' => EmployeeMilestone::TYPE_KGB,
        ];

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

                // US-5.5: Invalidasi milestone yang terpengaruh oleh perubahan konfigurasi
                if (isset($milestoneImpactingKeys[$key])) {
                    $milestoneType = $milestoneImpactingKeys[$key];
                    $this->invalidateMilestonesForConfigChange($milestoneType, $key, $oldVal, $newVal);
                }
            }
        }

        if ($changed) {
            session(['dynamic_audit_logs' => $dynamicLogs]);
        }
    }

    /**
     * Invalidasi milestone yang menggunakan versi konfigurasi lama.
     * Scheduler akan otomatis menggunakan fallback calculation dengan config terbaru.
     */
    private function invalidateMilestonesForConfigChange(string $milestoneType, string $configKey, string $oldVal, string $newVal): void
    {
        $invalidatedCount = EmployeeMilestone::where('type', $milestoneType)
            ->where('is_active', true)
            ->whereJsonContains('metadata->required_years', (int) $oldVal)
            ->update(['is_active' => false]);

        if ($invalidatedCount > 0) {
            \Log::info("Invalidated {$invalidatedCount} {$milestoneType} milestones due to config change: {$configKey} from {$oldVal} to {$newVal}");
        }
    }
}
