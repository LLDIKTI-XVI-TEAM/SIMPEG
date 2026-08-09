<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menyimpan konfigurasi rantai approval cuti per pegawai sebagai chain aktif baru.
 * Chain lama dinonaktifkan agar pengajuan baru memakai konfigurasi terbaru tanpa mengubah histori lama.
 */
class SaveEmployeeApprovalChainAction
{
    /**
     * @param  list<array{step_type:string, role_label:string, approver_employee_id:string, approver_role_key?:string|null, is_final:bool}>  $steps
     */
    public function execute(Employee $employee, array $steps, User $actor, string $reason, ?Request $request = null): LeaveApprovalChain
    {
        $steps = $this->appendGlobalPybmcWhenNeeded($steps);
        $this->ensureOneFinalStep($steps);
        $this->ensureFinalStepIsLast($steps);

        return DB::transaction(function () use ($employee, $steps, $actor, $reason, $request): LeaveApprovalChain {
            $oldChain = LeaveApprovalChain::query()
                ->where('employee_id', $employee->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            $oldValues = $oldChain?->load('steps')->toArray();

            if ($oldChain !== null) {
                $oldChain->update([
                    'is_active' => false,
                    'effective_until' => today(),
                    'updated_by' => $actor->id,
                ]);
            }

            $chain = LeaveApprovalChain::create([
                'employee_id' => $employee->id,
                'name' => 'Rantai approval cuti '.$employee->nama_lengkap,
                'is_active' => true,
                'effective_from' => today(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'change_reason' => $reason,
            ]);

            foreach (array_values($steps) as $index => $step) {
                $chain->steps()->create([
                    'step_order' => $index + 1,
                    'step_type' => $step['step_type'],
                    'role_label' => $step['role_label'],
                    'approver_employee_id' => $step['approver_employee_id'],
                    // Kunci peran ikut disimpan supaya salinan rantai tidak kehilangan metadata langkah
                    // yang sudah ada pada rantai sumber.
                    'approver_role_key' => $step['approver_role_key'] ?? null,
                    'is_final' => $step['is_final'],
                ]);
            }

            // Audit konfigurasi chain dicatat per chain baru agar perubahan approver dapat ditelusuri.
            // Ditulis fail-closed di dalam transaksi supaya kewenangan persetujuan tidak pernah
            // berpindah tanpa baris audit yang menerangkan siapa mengubahnya dan dari perangkat mana.
            AuditService::logOrFail(
                'CREATE',
                'LeaveApprovalChain',
                $chain->id,
                $oldValues,
                [
                    'employee_id' => $employee->id,
                    'steps' => $steps,
                    'reason' => $reason,
                ],
                $request,
            );

            return $chain->load('steps');
        });
    }

    /**
     * @param  list<array{step_type:string, role_label:string, approver_employee_id:string, is_final:bool}>  $steps
     */
    private function appendGlobalPybmcWhenNeeded(array $steps): array
    {
        if (collect($steps)->where('is_final', true)->isNotEmpty()) {
            return $steps;
        }

        $globalPybmc = LeavePybmcGlobalConfig::query()
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->first();

        if ($globalPybmc === null) {
            return $steps;
        }

        $steps[] = [
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $globalPybmc->approver_employee_id,
            'is_final' => true,
        ];

        return $steps;
    }

    /**
     * @param  list<array{step_type:string, role_label:string, approver_employee_id:string, is_final:bool}>  $steps
     */
    private function ensureOneFinalStep(array $steps): void
    {
        $finalCount = collect($steps)->where('is_final', true)->count();

        if ($finalCount !== 1) {
            throw new RuntimeException('Rantai approval cuti wajib memiliki tepat satu approver final.');
        }
    }

    /**
     * @param  list<array{step_type:string, role_label:string, approver_employee_id:string, is_final:bool}>  $steps
     */
    private function ensureFinalStepIsLast(array $steps): void
    {
        $lastStep = collect($steps)->last();

        if (($lastStep['is_final'] ?? false) !== true) {
            throw new RuntimeException('Approver final cuti wajib berada pada urutan terakhir.');
        }
    }
}
