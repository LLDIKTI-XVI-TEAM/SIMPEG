<?php

namespace App\Actions\Ews;

use App\Models\EmployeeMilestone;
use App\Models\EwsConfig;
use App\Services\AuditService;
use App\Services\Ews\EwsConfigCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateEwsConfigAction
{
    /**
     * Menyimpan konfigurasi dan audit dalam satu transaksi.
     *
     * Milestone yang bergantung pada nilai lama ikut dinonaktifkan agar scheduler
     * menghitung ulang tanggalnya dengan konfigurasi terbaru.
     */
    public function execute(Request $request): void
    {
        $reason = $request->input('reason');
        $milestoneImpactingKeys = [
            'pangkat_required_years' => EmployeeMilestone::TYPE_KENAIKAN_PANGKAT,
            'kgb_required_years' => EmployeeMilestone::TYPE_KGB,
        ];

        DB::transaction(function () use ($request, $reason, $milestoneImpactingKeys): void {
            foreach (array_keys(EwsConfigCatalog::LABELS) as $key) {
                $oldValue = EwsConfig::getVal($key);
                $newValue = $request->input($key);

                if ((string) $oldValue === (string) $newValue) {
                    continue;
                }

                EwsConfig::setVal($key, $newValue);

                // Kunci konfigurasi disimpan dalam payload karena targetnya tidak memiliki UUID.
                // Audit wajib berhasil agar perubahan konfigurasi tidak berjalan tanpa jejak.
                AuditService::logOrFail(
                    'UPDATE',
                    'EwsConfig',
                    null,
                    ['key' => $key, 'value' => (string) $oldValue],
                    ['key' => $key, 'value' => (string) $newValue, 'reason' => $reason],
                    $request
                );

                if (isset($milestoneImpactingKeys[$key])) {
                    $this->invalidateMilestonesForConfigChange(
                        $milestoneImpactingKeys[$key],
                        $key,
                        $oldValue,
                        $newValue,
                    );
                }
            }
        });
    }

    /**
     * Menonaktifkan milestone berversi lama supaya scheduler memakai kalkulasi terbaru.
     */
    private function invalidateMilestonesForConfigChange(
        string $milestoneType,
        string $configKey,
        mixed $oldValue,
        mixed $newValue,
    ): void {
        $invalidatedCount = EmployeeMilestone::query()
            ->where('type', $milestoneType)
            ->where('is_active', true)
            ->whereJsonContains('metadata->required_years', (int) $oldValue)
            ->update(['is_active' => false]);

        if ($invalidatedCount > 0) {
            Log::info('Milestone EWS dinonaktifkan setelah konfigurasi berubah.', [
                'milestone_type' => $milestoneType,
                'config_key' => $configKey,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'invalidated_count' => $invalidatedCount,
            ]);
        }
    }
}
