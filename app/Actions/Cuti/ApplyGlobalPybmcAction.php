<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveApprovalChainStep;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Cuti\ApprovalChainConfigurationLockService;
use App\Services\Cuti\ApprovalChainInvariantService;
use App\Services\Employees\EmployeeDashboardScopeService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menyimpan PYBMC global dan mengganti final approver semua chain aktif.
 * Snapshot pengajuan lama tidak disentuh agar riwayat approval tetap sah sesuai kondisi saat submit.
 */
class ApplyGlobalPybmcAction
{
    public function __construct(
        private readonly ApprovalChainInvariantService $invariants,
        private readonly ApprovalChainConfigurationLockService $configurationLock,
        private readonly EmployeeDashboardScopeService $employeeScope,
    ) {}

    public function execute(Employee $approver, User $actor, string $reason): LeavePybmcGlobalConfig
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($approver, $actor, $reason): LeavePybmcGlobalConfig {
            $this->configurationLock->acquire();
            // Role dan identitas akun dapat berubah selama antre lock; jangan memakai snapshot request.
            $actor->refresh();
            $this->authorize($actor);
            $selectedApproverId = $approver->getAttribute('id');
            $lockedApproverIds = $this->invariants
                ->lockActiveChainApproversForGlobalOverride($selectedApproverId);
            // Izin dapat dicabut ketika writer menunggu row approver, setelah configuration lock diperoleh.
            $actor->refresh();
            $this->authorize($actor);

            $config = LeavePybmcGlobalConfig::create([
                // Configuration lock menserialkan pemberian revisi bersama mutasi chain dan audit.
                'revision' => (int) LeavePybmcGlobalConfig::query()->where('revision', '>', 0)->max('revision') + 1,
                'approver_employee_id' => $selectedApproverId,
                'effective_from' => today(),
                'created_by' => $actor->id,
                'change_reason' => $reason,
            ]);

            $affectedChainCount = 0;

            // Pegawai approver sudah dikunci global lebih dulu; chain dan step kemudian dikunci per
            // chunk agar urutan lock konsisten dengan writer rantai lain dan penggunaan memori terbatas.
            LeaveApprovalChain::query()
                ->where('is_active', true)
                ->lockForUpdate()
                ->chunkById(100, function (Collection $chains) use (
                    $lockedApproverIds,
                    $selectedApproverId,
                    &$affectedChainCount,
                ): void {
                    $chainIds = $chains->modelKeys();
                    $stepsByChain = LeaveApprovalChainStep::query()
                        ->whereIn('leave_approval_chain_id', $chainIds)
                        ->orderBy('leave_approval_chain_id')
                        ->orderBy('step_order')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->groupBy('leave_approval_chain_id');

                    $currentChains = [];
                    $candidateChains = [];
                    $finalStepIds = [];

                    foreach ($chains as $chain) {
                        /** @var Collection<int, LeaveApprovalChainStep> $chainSteps */
                        $chainSteps = $stepsByChain->get($chain->id, new Collection)->values();
                        $currentSteps = $chainSteps
                            ->map(fn (LeaveApprovalChainStep $step): array => $this->invariantStep($step))
                            ->values()
                            ->all();
                        $candidateSteps = $currentSteps;

                        foreach ($chainSteps as $index => $step) {
                            if ($step->is_final) {
                                $candidateSteps[$index]['approver_employee_id'] = $selectedApproverId;
                                $finalStepIds[] = $step->id;
                            }
                        }

                        $currentChains[] = $currentSteps;
                        $candidateChains[] = $candidateSteps;
                    }

                    // Chain cacat ditolak sebelum perubahan; kandidat juga wajib tetap memenuhi
                    // invariant yang sama setelah PYBMC baru diterapkan.
                    $this->invariants->validateMany($currentChains, $lockedApproverIds);
                    $this->invariants->validateMany($candidateChains, $lockedApproverIds);

                    $updated = LeaveApprovalChainStep::query()
                        ->whereIn('id', $finalStepIds)
                        ->update(['approver_employee_id' => $selectedApproverId]);

                    if ($updated !== count($chains)) {
                        throw new RuntimeException(
                            'Jumlah final approver yang diperbarui tidak sesuai jumlah rantai approval aktif.',
                        );
                    }

                    $affectedChainCount += count($chains);
                });

            AuditService::logAsOrFail(
                $actor->id,
                $actor->name,
                'CREATE',
                'LeavePybmcGlobalConfig',
                $config->id,
                null,
                [
                    'approver_employee_id' => $selectedApproverId,
                    'approver_name' => $approver->nama_lengkap,
                    'reason' => $reason,
                    'affected_chain_count' => $affectedChainCount,
                ],
            );

            return $config;
        });
    }

    /**
     * PYBMC global mengubah seluruh chain sehingga memerlukan permission efektif
     * sekaligus scope global dari identitas asli, termasuk saat Action dipanggil langsung.
     */
    private function authorize(User $actor): void
    {
        abort_unless(
            $actor->hasPermission('cuti.configure')
                && $this->employeeScope->hasGlobalIdentityScope($actor),
            403,
            'Anda tidak lagi memiliki izin untuk tindakan ini. Perubahan tidak disimpan. Hubungi pengelola akses.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function invariantStep(LeaveApprovalChainStep $step): array
    {
        return [
            'step_type' => $step->step_type,
            'role_label' => $step->role_label,
            'approver_employee_id' => $step->approver_employee_id,
            'approver_role_key' => $step->approver_role_key,
            'is_final' => $step->is_final,
        ];
    }
}
