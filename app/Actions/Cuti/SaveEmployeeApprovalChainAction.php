<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Cuti\ApprovalChainConfigurationLockService;
use App\Services\Cuti\ApprovalChainInvariantService;
use App\Services\Employees\EmployeeDashboardScopeService;
use App\Support\Cuti\ApprovalStepLabel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Menyimpan konfigurasi rantai approval cuti per pegawai sebagai chain aktif baru.
 * Chain lama dinonaktifkan agar pengajuan baru memakai konfigurasi terbaru tanpa mengubah histori lama.
 */
class SaveEmployeeApprovalChainAction
{
    public function __construct(
        private readonly ApprovalChainInvariantService $invariants,
        private readonly ApprovalChainConfigurationLockService $configurationLock,
        private readonly EmployeeDashboardScopeService $employeeScope,
    ) {}

    /**
     * @param  list<array{
     *     step_type:mixed,
     *     role_label:mixed,
     *     approver_employee_id:mixed,
     *     approver_role_key?:mixed,
     *     is_final:mixed
     * }>  $steps
     */
    public function execute(Employee $employee, array $steps, User $actor, ?string $reason, ?Request $request = null): LeaveApprovalChain
    {
        $employee = $this->authorizedTarget($actor, $employee->id);

        return DB::transaction(function () use ($employee, $steps, $actor, $reason, $request): LeaveApprovalChain {
            $this->configurationLock->acquire();
            // Role dan identitas akun dapat berubah selama antre lock; jangan memakai snapshot request.
            $actor->refresh();
            // Recheck awal tidak mengambil row lock tersendiri: invariant di bawah
            // mengunci union target dan approver dalam urutan UUID deterministik.
            $employee = $this->authorizedTarget($actor, $employee->id);
            $steps = $this->appendGlobalPybmcWhenNeeded($steps);
            $steps = array_map(fn (array $step): array => [
                ...$step,
                'role_label' => ApprovalStepLabel::display(
                    (string) ($step['step_type'] ?? ''),
                    is_string($step['role_label'] ?? null) ? $step['role_label'] : null,
                ),
            ], $steps);

            // Kandidat wajib sah dan seluruh approver dikunci sebelum chain aktif lama disentuh,
            // supaya kegagalan konfigurasi tidak meninggalkan pergantian kewenangan secara parsial.
            try {
                $this->invariants->validateForEmployee($employee, $steps);
            } catch (QueryException $exception) {
                // Detail SQL dan binding merupakan error infrastruktur, bukan pesan validasi pengguna.
                throw $exception;
            } catch (RuntimeException $exception) {
                // Caller domain tetap menerima RuntimeException, sedangkan boundary HTTP memperoleh
                // error validasi yang dapat ditampilkan tanpa mengubah kontrak exception lainnya.
                if ($request === null) {
                    throw $exception;
                }

                throw ValidationException::withMessages([
                    'steps' => $exception->getMessage(),
                ]);
            }

            // Permission dan scope dibaca ulang setelah lock target/approver agar revoke
            // atau perubahan penugasan yang menunggu lock tidak lolos memakai state lama.
            $actor->refresh();
            $employee = $this->authorizedTarget($actor, $employee->id, true);

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
            AuditService::logAsOrFail(
                $actor->id,
                $actor->name,
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
     * Memeriksa capability sebelum detail domain dan mengambil ulang target melalui
     * scope identitas asli; respons 404 tidak membedakan ID asing dan target hilang.
     */
    private function authorizedTarget(User $actor, string $employeeId, bool $lock = false): Employee
    {
        abort_unless(
            $actor->hasPermission('cuti.configure'),
            403,
            'Anda tidak lagi memiliki izin untuk tindakan ini. Perubahan tidak disimpan. Hubungi pengelola akses.',
        );

        $query = $this->employeeScope->forIdentity($actor)->whereKey($employeeId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $target = $query->first();
        abort_if($target === null, 404);

        if (! $target->isActive()) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pegawai target harus berstatus Aktif.',
            ]);
        }

        return $target;
    }

    /**
     * @param  list<array{
     *     step_type:mixed,
     *     role_label:mixed,
     *     approver_employee_id:mixed,
     *     approver_role_key?:mixed,
     *     is_final:mixed
     * }>  $steps
     * @return list<array{
     *     step_type:mixed,
     *     role_label:mixed,
     *     approver_employee_id:mixed,
     *     approver_role_key?:mixed,
     *     is_final:mixed
     * }>
     */
    private function appendGlobalPybmcWhenNeeded(array $steps): array
    {
        if (collect($steps)->where('is_final', true)->isNotEmpty()) {
            return $steps;
        }

        $globalPybmc = LeavePybmcGlobalConfig::query()
            ->latestRevision()
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
}
