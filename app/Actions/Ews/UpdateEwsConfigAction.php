<?php

namespace App\Actions\Ews;

use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\EwsConfig;
use App\Services\AuditService;
use App\Services\Employees\TmtCalculatorService;
use App\Services\Ews\EwsConfigCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateEwsConfigAction
{
    private const GLOBAL_PENSION_CONFIG_KEY = 'pensiun_required_age_years';

    private const GLOBAL_PENSION_SOURCE = 'calculated_from_global_config';

    private const PENSION_SYNC_CHUNK_SIZE = 100;

    public function __construct(private readonly TmtCalculatorService $tmtCalculator) {}

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
            'pensiun_required_age_years' => EmployeeMilestone::TYPE_PENSIUN,
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

                if ($key === self::GLOBAL_PENSION_CONFIG_KEY) {
                    $this->syncGlobalPensionMilestones();

                    continue;
                }

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
     * Menghitung ulang hanya milestone pensiun yang benar-benar berasal dari fallback global.
     * Pegawai dikunci sebelum milestone agar semua writer memakai urutan kunci yang sama.
     */
    private function syncGlobalPensionMilestones(): void
    {
        Employee::query()
            ->select('employees.id')
            ->whereHas('milestones', function ($query): void {
                $query->where('type', EmployeeMilestone::TYPE_PENSIUN)
                    ->where('milestone_key', EmployeeMilestone::KEY_DEFAULT)
                    ->where('is_active', true)
                    ->where('metadata->source', self::GLOBAL_PENSION_SOURCE)
                    ->where('metadata->config_key', self::GLOBAL_PENSION_CONFIG_KEY);
            })
            ->orderBy('employees.id')
            ->chunkById(
                self::PENSION_SYNC_CHUNK_SIZE,
                function (Collection $employees): void {
                    /** @var Employee $candidate */
                    foreach ($employees as $candidate) {
                        $employee = Employee::query()
                            ->whereKey($candidate->getKey())
                            ->lockForUpdate()
                            ->firstOrFail();

                        $milestone = EmployeeMilestone::query()
                            ->where('employee_id', $employee->id)
                            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
                            ->where('milestone_key', EmployeeMilestone::KEY_DEFAULT)
                            ->where('is_active', true)
                            ->where('metadata->source', self::GLOBAL_PENSION_SOURCE)
                            ->where('metadata->config_key', self::GLOBAL_PENSION_CONFIG_KEY)
                            ->lockForUpdate()
                            ->first();

                        if ($milestone === null) {
                            continue;
                        }

                        // Versi lama dipertahankan nonaktif sebagai jejak kalkulasi BUP sebelumnya.
                        $milestone->update(['is_active' => false]);
                        $this->tmtCalculator->syncPensionForEmployee($employee, false);
                    }
                },
                'employees.id',
                'id',
            );
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
        $query = EmployeeMilestone::query()
            ->where('type', $milestoneType)
            ->where('is_active', true);

        if ($milestoneType === EmployeeMilestone::TYPE_PENSIUN) {
            $query->where('metadata->config_key', $configKey)
                ->where('metadata->source', 'calculated_from_global_config');
        } else {
            $query->whereJsonContains('metadata->required_years', (int) $oldValue);
        }

        $invalidatedCount = $query->update(['is_active' => false]);

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
