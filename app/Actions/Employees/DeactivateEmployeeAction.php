<?php

namespace App\Actions\Employees;

use App\Http\Requests\Employee\DeactivateEmployeeRequest;
use App\Models\Employee;
use App\Models\EmployeeStatusTransition;
use App\Models\RefStatusPegawai;
use App\Services\Employees\EmployeeStatusChangeResult;
use App\Services\Employees\EmployeeStatusLifecycleService;
use App\Services\Employees\EmployeeStatusTransitionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Menonaktifkan pegawai melalui primitive lifecycle bersama. */
class DeactivateEmployeeAction
{
    public function __construct(
        private readonly EmployeeStatusTransitionService $transitions,
        private readonly EmployeeStatusLifecycleService $lifecycle,
    ) {}

    public function execute(Employee $employee, Request $request): EmployeeStatusChangeResult
    {
        $tanggalEfektif = (string) $request->input('tanggal_efektif', now()->toDateString());
        $alasan = (string) $request->input('alasan', '');
        $note = trim((string) $request->input('status_note', ''));
        $note = $note === '' ? DeactivateEmployeeRequest::DEFAULT_NOTE : $note;
        $status = RefStatusPegawai::query()->where('kode', 'NONAKTIF')->firstOrFail();

        if (! $employee->isActive()) {
            throw ValidationException::withMessages(['status' => 'Pegawai sudah berstatus nonaktif.']);
        }

        if ($this->transitions->isFuture($tanggalEfektif)) {
            if ($this->transitions->isScheduled($employee, $tanggalEfektif)) {
                throw ValidationException::withMessages([
                    'tanggal_efektif' => 'Deaktivasi pada tanggal tersebut sudah dijadwalkan.',
                ]);
            }

            $this->transitions->schedule(
                $employee,
                $status,
                $tanggalEfektif,
                EmployeeStatusTransition::KIND_DEACTIVATE,
                $alasan,
                $note,
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
            $note,
            intent: EmployeeStatusLifecycleService::INTENT_DEACTIVATE,
        );

        if (! $result->changed) {
            throw ValidationException::withMessages(['status' => 'Pegawai sudah berstatus nonaktif.']);
        }

        $this->lifecycle->notify($result, EmployeeStatusLifecycleService::CONTEXT_DEACTIVATE);

        return new EmployeeStatusChangeResult(
            $result->employee,
            EmployeeStatusChangeResult::STATE_APPLIED,
            $tanggalEfektif,
        );
    }
}
