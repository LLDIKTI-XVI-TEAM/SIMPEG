<?php

namespace App\Actions\Cuti;

use App\Models\ApprovalConfig;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Membuat chain approval awal dari data kepala bagian dan ApprovalConfig lama.
 * Pegawai tanpa Kepala Bagian dilaporkan agar tidak terkunci diam-diam saat runtime dinamis aktif.
 */
class BackfillEmployeeApprovalChainsAction
{
    /** @var array<string, bool> */
    private array $kepalaBagianAktifById = [];

    public function __construct(private readonly SaveEmployeeApprovalChainAction $saveChain) {}

    /**
     * @return array{created_employee_ids:list<string>, skipped_employee_ids:list<string>, missing_kepala_bagian_employee_ids:list<string>, missing_final_approver_employee_ids:list<string>}
     */
    public function execute(User $actor, ?string $reason, ?Request $request = null): array
    {
        // Instance Action dapat dipakai ulang oleh container; cache harus selalu mengikuti status
        // pegawai terbaru pada setiap eksekusi backfill administratif.
        $this->kepalaBagianAktifById = [];
        $legacyApprovers = $this->legacyApproverEmployees();
        $result = [
            'created_employee_ids' => [],
            'skipped_employee_ids' => [],
            'missing_kepala_bagian_employee_ids' => [],
            'missing_final_approver_employee_ids' => [],
        ];

        Employee::query()
            ->whereActiveStatus()
            ->chunkById(100, function (Collection $employees) use (&$result, $legacyApprovers, $actor, $reason, $request): void {
                foreach ($employees as $employee) {
                    if (LeaveApprovalChain::where('employee_id', $employee->id)->where('is_active', true)->exists()) {
                        $result['skipped_employee_ids'][] = $employee->id;

                        continue;
                    }

                    $kepalaBagianId = $employee->currentSupervisor()?->kepala_bagian_id;

                    // Penugasan efektif masih dapat menunjuk pejabat yang sudah nonaktif. Target
                    // tersebut dilaporkan sebagai Kepala Bagian tidak tersedia agar satu data
                    // bermasalah tidak menghentikan target valid lain dalam proses backfill.
                    if ($kepalaBagianId === null || ! $this->kepalaBagianAktif($kepalaBagianId)) {
                        $result['missing_kepala_bagian_employee_ids'][] = $employee->id;

                        continue;
                    }

                    $steps = [];

                    foreach ($legacyApprovers as $legacyApprover) {
                        if ($legacyApprover['step_type'] === 'verifier') {
                            $steps[] = $legacyApprover;
                        }
                    }

                    // Kepala Bagian selalu berasal dari penugasan efektif. Kolom snapshot pegawai
                    // tidak dipakai sebagai fallback agar backfill tidak mengabadikan atasan lama.
                    $steps[] = [
                        'step_type' => 'kepala_bagian',
                        'role_label' => 'Atasan Langsung',
                        'approver_employee_id' => $kepalaBagianId,
                        'is_final' => false,
                    ];

                    foreach ($legacyApprovers as $legacyApprover) {
                        if ($legacyApprover['step_type'] === 'pybmc') {
                            $steps[] = $legacyApprover;
                        }
                    }

                    if (collect($steps)->where('is_final', true)->count() !== 1) {
                        $result['missing_final_approver_employee_ids'][] = $employee->id;

                        continue;
                    }

                    $this->saveChain->execute($employee, $steps, $actor, $reason, $request);
                    $result['created_employee_ids'][] = $employee->id;
                }
            });

        return $result;
    }

    private function kepalaBagianAktif(string $employeeId): bool
    {
        return $this->kepalaBagianAktifById[$employeeId] ??= Employee::query()
            ->whereKey($employeeId)
            ->whereActiveStatus()
            ->exists();
    }

    /**
     * @return list<array{step_type:string, role_label:string, approver_employee_id:string, is_final:bool}>
     */
    private function legacyApproverEmployees(): array
    {
        $stage2 = $this->employeeIdFromConfig('stage2_approver_id');
        $stage3 = $this->employeeIdFromConfig('stage3_approver_id');
        $steps = [];

        if ($stage2 !== null) {
            $steps[] = [
                'step_type' => 'verifier',
                'role_label' => 'Verifikator',
                'approver_employee_id' => $stage2,
                'is_final' => false,
            ];
        }

        if ($stage3 !== null) {
            $steps[] = [
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $stage3,
                'is_final' => true,
            ];
        }

        return $steps;
    }

    private function employeeIdFromConfig(string $key): ?string
    {
        $userId = ApprovalConfig::getVal($key);

        if ($userId === null) {
            return null;
        }

        return User::find($userId)?->employee_id;
    }
}
