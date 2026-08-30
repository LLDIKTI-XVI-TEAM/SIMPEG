<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveApprovalChainStep;
use App\Models\SupervisorAssignment;
use App\Services\AuditService;
use App\Services\Cuti\ApprovalChainConfigurationLockService;
use App\Services\Cuti\ApprovalChainInvariantService;
use App\Services\Employees\SupervisorAssignmentTimelineService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Mengatur riwayat Kepala Bagian efektif tanpa membentuk rentang tanggal yang tumpang tindih.
 */
class AssignSupervisorAction
{
    private const LOCK_PREFIX = 'simpeg.supervisor_assignment:';

    public function __construct(
        private readonly AuditService $audit,
        private readonly ApprovalChainInvariantService $invariants,
        private readonly SupervisorAssignmentTimelineService $timeline,
        private readonly ApprovalChainConfigurationLockService $configurationLock,
    ) {}

    /**
     * Menyimpan penugasan pada tanggal efektif dan menyelaraskan pointer serta chain yang berlaku hari ini.
     *
     * @throws ValidationException
     */
    public function execute(
        Employee $employee,
        ?string $kepalaBagianId,
        string $effectiveDate,
        ?Request $request = null,
    ): Employee {
        if ($kepalaBagianId === $employee->id) {
            throw ValidationException::withMessages([
                'kepala_bagian_id' => 'Pegawai tidak bisa menjadi kepala bagian untuk diri sendiri.',
            ]);
        }

        $effective = Carbon::createFromFormat('Y-m-d', $effectiveDate)->startOfDay();
        $today = today();

        DB::transaction(function () use ($employee, $kepalaBagianId, $effective, $today, $request): void {
            // Penugasan mendatang tetap memengaruhi sumber Kepala Bagian yang kelak dibaca resolver;
            // seluruh writer timeline wajib masuk melalui urutan lock konfigurasi yang sama.
            $this->configurationLock->acquire();
            $this->lockAssignmentTimeline($employee);

            /** @var Collection<int, SupervisorAssignment> $assignments */
            $assignments = SupervisorAssignment::query()
                ->where('employee_id', $employee->id)
                ->orderBy('tanggal_mulai')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $timelinePlan = $this->timeline->plan(
                $assignments,
                $kepalaBagianId,
                $effective,
                $today,
            );

            // Penetapan identik pada tanggal yang sama adalah no-op agar histori dan audit tidak berlipat.
            if ($timelinePlan['is_no_op']) {
                $this->validateSelectedSupervisor($employee, $kepalaBagianId);

                return;
            }

            if ($effective->gt($today)) {
                $this->validateSelectedSupervisor($employee, $kepalaBagianId);
                $lockedEmployee = $this->lockEmployee($employee);
                $oldPointer = $lockedEmployee->kepala_bagian_id;

                $this->timeline->persist($employee, $timelinePlan);
                $this->timeline->assertNoOverlap($employee);

                // Penugasan masa depan hanya mengubah histori terjadwal. Pointer dan chain hari ini
                // tetap utuh sampai tanggal tersebut benar-benar berlaku.
                $this->auditAssignment($lockedEmployee, $oldPointer, $oldPointer, $kepalaBagianId, $effective, $request);

                return;
            }

            $projectedTodaySupervisorId = $timelinePlan['projected_today_supervisor_id'];
            $candidateSnapshot = $projectedTodaySupervisorId === null
                ? null
                : $this->activeChainSnapshot($employee, $projectedTodaySupervisorId);

            $this->validateCandidateBeforeMutation(
                $employee,
                $candidateSnapshot,
                $kepalaBagianId,
            );

            $lockedCandidateSnapshot = $projectedTodaySupervisorId === null
                ? null
                : $this->lockedActiveChainSnapshot($employee, $projectedTodaySupervisorId);

            if ($candidateSnapshot !== $lockedCandidateSnapshot) {
                throw ValidationException::withMessages([
                    'kepala_bagian_id' => 'Rantai approval aktif berubah saat penugasan diproses. Silakan ulangi.',
                ]);
            }

            $lockedEmployee = $this->lockEmployee($employee);
            $oldPointer = $lockedEmployee->kepala_bagian_id;

            $this->timeline->persist($employee, $timelinePlan);

            $this->timeline->assertNoOverlap($employee);

            $todayAssignment = $this->timeline->assignmentAt($lockedEmployee, $today, lock: true);
            $todaySupervisorId = $todayAssignment?->kepala_bagian_id;

            if ($todaySupervisorId !== $projectedTodaySupervisorId) {
                throw ValidationException::withMessages([
                    'effective_date' => 'Penugasan Kepala Bagian berubah saat diproses. Silakan ulangi.',
                ]);
            }

            $lockedEmployee->update(['kepala_bagian_id' => $todaySupervisorId]);

            if ($todaySupervisorId !== null && $lockedCandidateSnapshot !== null) {
                $this->syncActiveChain($lockedCandidateSnapshot, $todaySupervisorId);
            }

            $this->auditAssignment(
                $lockedEmployee,
                $oldPointer,
                $todaySupervisorId,
                $kepalaBagianId,
                $effective,
                $request,
            );
        });

        return $employee->refresh()->load('kepalaBagian');
    }

    private function validateSelectedSupervisor(Employee $employee, ?string $kepalaBagianId): void
    {
        if ($kepalaBagianId === null) {
            return;
        }

        try {
            // Target dan seluruh calon approver dikunci bersama dalam urutan UUID global agar
            // penugasan silang tidak membentuk siklus lock antarpegawai.
            $this->invariants->validateApproverIds([$kepalaBagianId], [$employee->id]);
        } catch (QueryException $exception) {
            // Detail SQL dan binding merupakan error infrastruktur, bukan pesan validasi pengguna.
            throw $exception;
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'kepala_bagian_id' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{
     *     chain_id:string,
     *     kepala_bagian_step_id:string|null,
     *     steps:list<array{
     *         id:string,
     *         step_order:int,
     *         step_type:string,
     *         role_label:string,
     *         approver_employee_id:mixed,
     *         approver_role_key:mixed,
     *         is_final:bool
     *     }>
     * }|null  $candidateSnapshot
     */
    private function validateCandidateBeforeMutation(
        Employee $employee,
        ?array $candidateSnapshot,
        ?string $kepalaBagianId,
    ): void {
        try {
            $additionalApprovers = $kepalaBagianId === null ? [] : [$kepalaBagianId];

            if ($candidateSnapshot === null) {
                $this->invariants->validateApproverIds($additionalApprovers, [$employee->id]);

                return;
            }

            $this->invariants->validate(
                $candidateSnapshot['steps'],
                $additionalApprovers,
                [$employee->id],
            );
        } catch (QueryException $exception) {
            // Detail SQL dan binding merupakan error infrastruktur, bukan pesan validasi pengguna.
            throw $exception;
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'kepala_bagian_id' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{
     *     chain_id:string,
     *     kepala_bagian_step_id:string|null,
     *     steps:list<array{
     *         id:string,
     *         step_order:int,
     *         step_type:string,
     *         role_label:string,
     *         approver_employee_id:mixed,
     *         approver_role_key:mixed,
     *         is_final:bool
     *     }>
     * }|null
     */
    private function activeChainSnapshot(Employee $employee, string $kepalaBagianId, bool $lock = false): ?array
    {
        $chainQuery = LeaveApprovalChain::query()
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->orderBy('id');

        if ($lock) {
            $chainQuery->lockForUpdate();
        }

        $chain = $chainQuery->first();

        if ($chain === null) {
            return null;
        }

        $stepsQuery = $chain->steps()
            ->orderBy('step_order')
            ->orderBy('id');

        if ($lock) {
            $stepsQuery->lockForUpdate();
        }

        $kepalaBagianStepId = null;
        $steps = $stepsQuery->get()->map(function (LeaveApprovalChainStep $step) use ($kepalaBagianId, &$kepalaBagianStepId): array {
            $approverId = $step->approver_employee_id;

            if ($kepalaBagianStepId === null && $step->step_type === 'kepala_bagian') {
                $kepalaBagianStepId = $step->id;
                $approverId = $kepalaBagianId;
            }

            return [
                'id' => $step->id,
                'step_order' => $step->step_order,
                'step_type' => $step->step_type,
                'role_label' => $step->role_label,
                'approver_employee_id' => $approverId,
                'approver_role_key' => $step->approver_role_key,
                'is_final' => $step->is_final,
            ];
        })->values()->all();

        return [
            'chain_id' => $chain->id,
            'kepala_bagian_step_id' => $kepalaBagianStepId,
            'steps' => $steps,
        ];
    }

    /**
     * Mengunci ulang snapshot yang sudah divalidasi agar perubahan serentak gagal tertutup.
     */
    private function lockedActiveChainSnapshot(Employee $employee, string $kepalaBagianId): ?array
    {
        return $this->activeChainSnapshot($employee, $kepalaBagianId, lock: true);
    }

    private function lockEmployee(Employee $employee): Employee
    {
        return Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Menserialkan perubahan timeline per pegawai, termasuk saat histori masih kosong dan belum ada baris untuk dikunci.
     */
    private function lockAssignmentTimeline(Employee $employee): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select(
            'select pg_advisory_xact_lock(hashtextextended(?, 0))',
            [self::LOCK_PREFIX.$employee->id],
        );
    }

    /**
     * @param  array{
     *     chain_id:string,
     *     kepala_bagian_step_id:string|null,
     *     steps:list<array<string, mixed>>
     * }  $lockedCandidateSnapshot
     */
    private function syncActiveChain(array $lockedCandidateSnapshot, string $kepalaBagianId): void
    {
        $stepId = $lockedCandidateSnapshot['kepala_bagian_step_id'];

        if ($stepId === null) {
            throw ValidationException::withMessages([
                'kepala_bagian_id' => 'Rantai approval aktif tidak memiliki langkah Kepala Bagian.',
            ]);
        }

        $updated = LeaveApprovalChainStep::query()
            ->whereKey($stepId)
            ->where('leave_approval_chain_id', $lockedCandidateSnapshot['chain_id'])
            ->update(['approver_employee_id' => $kepalaBagianId]);

        if ($updated !== 1) {
            throw ValidationException::withMessages([
                'kepala_bagian_id' => 'Langkah Kepala Bagian pada rantai approval aktif tidak ditemukan.',
            ]);
        }
    }

    /**
     * Audit berada dalam transaksi agar histori, pointer, dan chain tidak berubah tanpa jejak.
     */
    private function auditAssignment(
        Employee $employee,
        ?string $oldPointer,
        ?string $todaySupervisorId,
        ?string $assignedSupervisorId,
        Carbon $effective,
        ?Request $request,
    ): void {
        $this->audit->logOrFail(
            'UPDATE',
            'Employee',
            $employee->id,
            ['kepala_bagian_id' => $oldPointer],
            [
                'kepala_bagian_id' => $todaySupervisorId,
                'assigned_kepala_bagian_id' => $assignedSupervisorId,
                'effective_date' => $effective->toDateString(),
            ],
            $request,
        );
    }
}
