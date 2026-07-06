<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveApprovalChainStep;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Me-resolve chain approval aktif menjadi step siap snapshot.
 * Duplikasi approver tetap disnapshot agar runtime approval mencatat skip otomatis sebagai jejak audit.
 */
class ApprovalChainResolver
{
    /** @return Collection<int, LeaveApprovalChainStep> */
    public function resolveEffectiveSteps(Employee $employee): Collection
    {
        $chain = LeaveApprovalChain::query()
            ->with(['steps' => fn ($query) => $query->orderBy('step_order')])
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->first();

        if ($chain === null) {
            throw new RuntimeException('Konfigurasi approval cuti pegawai belum tersedia.');
        }

        foreach ($chain->steps as $step) {
            if ($step->approver_employee_id === null) {
                throw new RuntimeException("Approver {$step->role_label} belum tersedia pada konfigurasi approval cuti.");
            }
        }

        if ($chain->steps->where('is_final', true)->count() !== 1) {
            throw new RuntimeException('Rantai approval cuti wajib memiliki tepat satu approver final.');
        }

        return $chain->steps->values();
    }
}
