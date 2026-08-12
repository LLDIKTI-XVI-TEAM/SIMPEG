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

    private const MILESTONE_SYNC_CHUNK_SIZE = 100;

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
                    $this->invalidateAndResyncMilestonesForConfigChange(
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
                self::MILESTONE_SYNC_CHUNK_SIZE,
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
     * Menonaktifkan milestone berversi lama dan langsung mempersistensikan ulang milestone
     * baru berdasarkan konfigurasi terkini, per pegawai terdampak.
     *
     * Setelah method ini selesai, milestone baru sudah tersimpan di database sehingga
     * scheduler tidak perlu menghitung ulang dari nol setiap hari.
     */
    private function invalidateAndResyncMilestonesForConfigChange(
        string $milestoneType,
        string $configKey,
        mixed $oldValue,
        mixed $newValue,
    ): void {
        $invalidatedCount = 0;
        $resyncedCount = 0;

        // Kandidat dipindai secara bounded tanpa mengambil lock milestone. Setiap writer
        // kemudian mengunci pegawai lebih dahulu agar urutannya sama dengan kalkulator TMT.
        Employee::query()
            ->select('employees.id')
            ->whereHas('milestones', function ($query) use ($milestoneType, $configKey, $oldValue): void {
                $query->where('type', $milestoneType)
                    ->where('is_active', true);

                if ($milestoneType === EmployeeMilestone::TYPE_PENSIUN) {
                    $query->where('metadata->config_key', $configKey)
                        ->where('metadata->source', self::GLOBAL_PENSION_SOURCE);

                    return;
                }

                $query->whereJsonContains('metadata->required_years', (int) $oldValue);
            })
            ->orderBy('employees.id')
            ->chunkById(
                self::MILESTONE_SYNC_CHUNK_SIZE,
                function (Collection $employees) use (
                    $milestoneType,
                    $configKey,
                    $oldValue,
                    &$invalidatedCount,
                    &$resyncedCount,
                ): void {
                    /** @var Employee $candidate */
                    foreach ($employees as $candidate) {
                        $employee = Employee::query()
                            ->whereKey($candidate->getKey())
                            ->lockForUpdate()
                            ->firstOrFail();

                        $milestones = EmployeeMilestone::query()
                            ->where('employee_id', $employee->id)
                            ->where('type', $milestoneType)
                            ->where('is_active', true);

                        if ($milestoneType === EmployeeMilestone::TYPE_PENSIUN) {
                            $milestones->where('metadata->config_key', $configKey)
                                ->where('metadata->source', self::GLOBAL_PENSION_SOURCE);
                        } else {
                            $milestones->whereJsonContains('metadata->required_years', (int) $oldValue);
                        }

                        // Recheck dilakukan setelah pegawai terkunci agar kandidat yang sudah
                        // diselesaikan writer lain tidak dinonaktifkan ulang atau dihitung ganda.
                        $lockedMilestones = $milestones->lockForUpdate()->get();

                        if ($lockedMilestones->isEmpty()) {
                            continue;
                        }

                        foreach ($lockedMilestones as $milestone) {
                            $milestone->update(['is_active' => false]);
                        }

                        $invalidatedCount += $lockedMilestones->count();
                        $this->tmtCalculator->syncForEmployee($employee);
                        $resyncedCount++;
                    }
                },
                'employees.id',
                'id',
            );

        if ($invalidatedCount > 0) {
            Log::info('Milestone EWS dinonaktifkan setelah konfigurasi berubah.', [
                'milestone_type' => $milestoneType,
                'config_key' => $configKey,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'invalidated_count' => $invalidatedCount,
            ]);
        }

        if ($resyncedCount > 0) {
            Log::info('Milestone EWS direkalkulasi dan dipersistensikan setelah konfigurasi berubah.', [
                'milestone_type' => $milestoneType,
                'config_key' => $configKey,
                'resynced_count' => $resyncedCount,
            ]);
        }
    }
}
