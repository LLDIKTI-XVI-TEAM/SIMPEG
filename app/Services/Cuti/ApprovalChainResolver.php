<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveApprovalChainStep;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Me-resolve chain approval aktif menjadi step efektif siap snapshot.
 * Duplikasi approver di-skip dengan mempertahankan kemunculan terakhir agar PYBMC final tetap berwenang.
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

        $effective = new Collection;
        $seenApprovers = [];

        foreach ($chain->steps->reverse()->values() as $step) {
            if ($step->approver_employee_id === null) {
                continue;
            }

            if (isset($seenApprovers[$step->approver_employee_id])) {
                continue;
            }

            $seenApprovers[$step->approver_employee_id] = true;
            $effective->prepend($step);
        }

        if ($effective->where('is_final', true)->count() !== 1) {
            throw new RuntimeException('Rantai approval cuti wajib memiliki tepat satu approver final efektif.');
        }

        return $effective->values();
    }
}
