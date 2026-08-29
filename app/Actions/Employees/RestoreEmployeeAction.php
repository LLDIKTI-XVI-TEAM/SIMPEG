<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\EmployeeStatusTransition;
use App\Models\RefStatusPegawai;
use App\Services\Employees\EmployeeStatusChangeResult;
use App\Services\Employees\EmployeeStatusLifecycleService;
use App\Services\Employees\EmployeeStatusTransitionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Mengaktifkan kembali pegawai melalui primitive lifecycle bersama. */
class RestoreEmployeeAction
{
    public function __construct(
        private readonly EmployeeStatusTransitionService $transitions,
        private readonly EmployeeStatusLifecycleService $lifecycle,
    ) {}

    public function execute(Employee $employee, Request $request): EmployeeStatusChangeResult
    {
        $tanggalEfektif = (string) $request->input('tanggal_efektif', now()->toDateString());
        $alasan = (string) $request->input('alasan', '');
        $status = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();

        if ($employee->isActive()) {
            throw ValidationException::withMessages(['status' => 'Pegawai sudah berstatus aktif.']);
        }

        if ($this->transitions->isFuture($tanggalEfektif)) {
            if ($this->transitions->isScheduled($employee, $tanggalEfektif)) {
                throw ValidationException::withMessages([
                    'tanggal_efektif' => 'Pemulihan pada tanggal tersebut sudah dijadwalkan.',
                ]);
            }

            $this->transitions->schedule(
                $employee,
                $status,
                $tanggalEfektif,
                EmployeeStatusTransition::KIND_RESTORE,
                $alasan,
                actorContext: $request,
            );

            return new EmployeeStatusChangeResult(
                $employee,
                EmployeeStatusChangeResult::STATE_SCHEDULED,
                $tanggalEfektif,
            );
        }

        $result = $this->lifecycle->mutate(
            $employee,
            $status,
            $tanggalEfektif,
            $alasan,
            $request,
            intent: EmployeeStatusLifecycleService::INTENT_RESTORE,
        );

        if (! $result->changed) {
            throw ValidationException::withMessages(['status' => 'Pegawai sudah berstatus aktif.']);
        }

        $this->lifecycle->notify($result, EmployeeStatusLifecycleService::CONTEXT_RESTORE);

        return new EmployeeStatusChangeResult(
            $result->employee,
            EmployeeStatusChangeResult::STATE_APPLIED,
            $tanggalEfektif,
        );
    }
}
