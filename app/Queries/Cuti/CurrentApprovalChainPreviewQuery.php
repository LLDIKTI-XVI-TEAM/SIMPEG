<?php

namespace App\Queries\Cuti;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveApprovalChainStep;
use App\Support\Cuti\ApprovalStepLabel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class CurrentApprovalChainPreviewQuery
{
    private const MAX_STEPS = 10;

    /**
     * Membaca satu chain aktif apa adanya untuk dipreview tanpa resolver, fallback, atau perubahan konfigurasi.
     *
     * @return array{available:bool,valid:bool,warnings:list<string>,steps:list<array{step_order:int,step_type:string,role_label:string,is_final:bool,approver:?array{id:string,nama_lengkap:string,nip:string,jabatan_terakhir:?string,status_aktif:?string}}>}
     */
    public function forEmployee(?string $employeeId): array
    {
        if (! is_string($employeeId) || ! Str::isUuid($employeeId)) {
            return $this->unavailable('Pegawai untuk preview chain tidak tersedia.');
        }

        $usesLegacyEmployeeSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive(Employee::class), true);

        // Batas dua chain membedakan data kosong dari konfigurasi ambigu tanpa memilih salah satu secara diam-diam.
        $chains = LeaveApprovalChain::query()
            ->select(['id', 'employee_id'])
            ->withCount('steps')
            ->with([
                'steps' => fn ($query) => $query
                    ->select([
                        'leave_approval_chain_steps.id',
                        'leave_approval_chain_steps.leave_approval_chain_id',
                        'leave_approval_chain_steps.step_order',
                        'leave_approval_chain_steps.step_type',
                        'leave_approval_chain_steps.role_label',
                        'leave_approval_chain_steps.approver_employee_id',
                        'leave_approval_chain_steps.is_final',
                        'preview_approvers.id as preview_approver_id',
                        'preview_approvers.nama_lengkap as preview_approver_nama_lengkap',
                        'preview_approvers.nip as preview_approver_nip',
                        'preview_approvers.jabatan_terakhir as preview_approver_jabatan_terakhir',
                        'preview_approvers.status_aktif as preview_approver_status_aktif',
                        'preview_approver_statuses.kelompok as preview_approver_status_group',
                    ])
                    // Join aman menggantikan eager-load approver agar preview tetap tiga data ringkas dalam dua query.
                    ->leftJoin('employees as preview_approvers', function ($join) use ($usesLegacyEmployeeSoftDeletes): void {
                        $join->on('preview_approvers.id', '=', 'leave_approval_chain_steps.approver_employee_id');

                        // deleted_at hanya compatibility selama model Employee masih memakai lifecycle SoftDeletes lama.
                        if ($usesLegacyEmployeeSoftDeletes) {
                            $join->whereNull('preview_approvers.deleted_at');
                        }
                    })
                    // Referensi status adalah sumber aktivitas; referensi hilang/tidak dikenal harus gagal aman.
                    ->leftJoin(
                        'ref_status_pegawai as preview_approver_statuses',
                        'preview_approver_statuses.id',
                        '=',
                        'preview_approvers.status_pegawai_id',
                    )
                    ->orderBy('step_order')
                    ->limit(self::MAX_STEPS),
            ])
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->limit(2)
            ->get();

        if ($chains->isEmpty()) {
            return $this->unavailable('Chain persetujuan aktif belum tersedia.');
        }

        if ($chains->count() > 1) {
            return $this->unavailable('Konfigurasi chain aktif ambigu dan tidak dapat dipreview.');
        }

        $chain = $chains->firstOrFail();
        $warnings = $this->warnings($chain);

        return [
            'available' => true,
            'valid' => $warnings === [],
            'warnings' => $warnings,
            'steps' => $chain->steps
                ->map(fn (LeaveApprovalChainStep $step): array => $this->stepPayload($step))
                ->all(),
        ];
    }

    /** @return list<string> */
    private function warnings(LeaveApprovalChain $chain): array
    {
        $warnings = [];
        $steps = $chain->steps;

        if ($chain->steps_count < 2 || $chain->steps_count > self::MAX_STEPS) {
            $warnings[] = 'Jumlah tahap chain harus berada antara 2 dan 10.';
        }

        $kepalaBagianIndex = null;
        $pybmcIndex = null;
        $finalIndexes = [];

        foreach ($steps as $index => $step) {
            if ($step->step_order !== $index + 1) {
                $warnings[] = 'Urutan tahap chain tidak kontigu.';
            }

            if (! in_array($step->step_type, ['verifier', 'kepala_bagian', 'pybmc'], true)) {
                $warnings[] = 'Jenis tahap chain tidak dikenali.';
            }

            if (! is_string($step->getAttribute('preview_approver_id'))) {
                $warnings[] = 'Approver pada salah satu tahap chain tidak tersedia.';
            } elseif (! $this->isActiveStatusGroup($step->getAttribute('preview_approver_status_group'))) {
                $warnings[] = 'Approver pada salah satu tahap chain tidak aktif.';
            }

            if ($step->is_final) {
                $finalIndexes[] = $index;
            }

            if ($step->step_type === 'kepala_bagian') {
                $kepalaBagianIndex = $index;
            }

            if ($step->step_type === 'pybmc') {
                $pybmcIndex = $index;
            }
        }

        if ($steps->where('step_type', 'kepala_bagian')->count() !== 1) {
            $warnings[] = 'Chain harus memiliki tepat satu tahap Atasan Langsung.';
        }

        if ($steps->where('step_type', 'pybmc')->count() !== 1) {
            $warnings[] = 'Chain harus memiliki tepat satu tahap PYBMC.';
        }

        if ($kepalaBagianIndex !== null && $steps->take($kepalaBagianIndex)->contains('step_type', 'pybmc')) {
            $warnings[] = 'PYBMC tidak boleh mendahului Atasan Langsung.';
        }

        if (count($finalIndexes) !== 1) {
            $warnings[] = 'Chain harus memiliki tepat satu approver final.';
        } else {
            $finalStep = $steps->get($finalIndexes[0]);

            if ($finalIndexes[0] !== $steps->count() - 1) {
                $warnings[] = 'Approver final harus berada pada tahap terakhir.';
            }

            if ($finalStep?->step_type !== 'pybmc') {
                $warnings[] = 'Approver final harus bertipe PYBMC.';
            }
        }

        if ($pybmcIndex === null || $pybmcIndex !== $steps->count() - 1 || ! $steps->last()?->is_final) {
            $warnings[] = 'PYBMC final harus berada pada tahap terakhir.';
        }

        if ($kepalaBagianIndex !== null && $steps->skip($kepalaBagianIndex + 1)->contains('step_type', 'verifier')) {
            $warnings[] = 'Verifier tidak boleh berada setelah Atasan Langsung.';
        }

        return array_values(array_unique($warnings));
    }

    /** Status aktif mengikuti klasifikasi reference table, termasuk kategori aktif khusus. */
    private function isActiveStatusGroup(mixed $statusGroup): bool
    {
        return is_string($statusGroup)
            && in_array(mb_strtolower(trim($statusGroup)), ['aktif', 'aktif/khusus'], true);
    }

    /** @return array{step_order:int,step_type:string,role_label:string,is_final:bool,approver:?array{id:string,nama_lengkap:string,nip:string,jabatan_terakhir:?string,status_aktif:?string}} */
    private function stepPayload(LeaveApprovalChainStep $step): array
    {
        $approverId = $step->getAttribute('preview_approver_id');

        return [
            'step_order' => $step->step_order,
            'step_type' => $step->step_type,
            'role_label' => ApprovalStepLabel::display($step->step_type, $step->role_label),
            'is_final' => $step->is_final,
            'approver' => ! is_string($approverId) ? null : [
                'id' => $approverId,
                'nama_lengkap' => (string) $step->getAttribute('preview_approver_nama_lengkap'),
                'nip' => (string) $step->getAttribute('preview_approver_nip'),
                'jabatan_terakhir' => $step->getAttribute('preview_approver_jabatan_terakhir'),
                'status_aktif' => $step->getAttribute('preview_approver_status_aktif'),
            ],
        ];
    }

    /**
     * @return array{available:false,valid:false,warnings:list<string>,steps:list<array{step_order:int,step_type:string,role_label:string,is_final:bool,approver:?array{id:string,nama_lengkap:string,nip:string,jabatan_terakhir:?string,status_aktif:?string}}>}
     */
    private function unavailable(string $warning): array
    {
        return [
            'available' => false,
            'valid' => false,
            'warnings' => [$warning],
            'steps' => [],
        ];
    }
}
