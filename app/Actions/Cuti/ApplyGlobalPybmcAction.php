<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan PYBMC global dan mengganti final approver semua chain aktif.
 * Snapshot pengajuan lama tidak disentuh agar riwayat approval tetap sah sesuai kondisi saat submit.
 */
class ApplyGlobalPybmcAction
{
    public function execute(Employee $approver, User $actor, string $reason): LeavePybmcGlobalConfig
    {
        return DB::transaction(function () use ($approver, $actor, $reason): LeavePybmcGlobalConfig {
            $config = LeavePybmcGlobalConfig::create([
                'approver_employee_id' => $approver->id,
                'effective_from' => today(),
                'created_by' => $actor->id,
                'change_reason' => $reason,
            ]);

            $affectedChainCount = 0;

            // Chunk membatasi model yang dimuat sekaligus; lock mencegah final step berubah di tengah override.
            LeaveApprovalChain::query()
                ->where('is_active', true)
                ->lockForUpdate()
                ->orderBy('id')
                ->chunkById(100, function ($chains) use ($approver, &$affectedChainCount): void {
                    foreach ($chains as $chain) {
                        $finalSteps = $chain->steps()
                            ->where('is_final', true)
                            ->lockForUpdate()
                            ->get();

                        if ($finalSteps->count() > 1) {
                            throw new MultipleRecordsFoundException($finalSteps->count());
                        }

                        $finalStep = $finalSteps->first();

                        if ($finalStep === null) {
                            // Chain legacy tanpa final diperbaiki tanpa mengurutkan ulang step non-final yang ada.
                            $chain->steps()->create([
                                'step_order' => ((int) $chain->steps()->max('step_order')) + 1,
                                'step_type' => 'pybmc',
                                'role_label' => 'PYBMC',
                                'approver_employee_id' => $approver->id,
                                'is_final' => true,
                            ]);
                            $affectedChainCount++;

                            continue;
                        }

                        $finalStep->update([
                            'step_type' => 'pybmc',
                            'role_label' => 'PYBMC',
                            'approver_employee_id' => $approver->id,
                            'is_final' => true,
                        ]);
                        $affectedChainCount++;
                    }
                });

            AuditService::log(
                'CREATE',
                'LeavePybmcGlobalConfig',
                $config->id,
                null,
                [
                    'approver_employee_id' => $approver->id,
                    'approver_name' => $approver->nama_lengkap,
                    'reason' => $reason,
                    'affected_chain_count' => $affectedChainCount,
                ],
            );

            return $config;
        });
    }
}
