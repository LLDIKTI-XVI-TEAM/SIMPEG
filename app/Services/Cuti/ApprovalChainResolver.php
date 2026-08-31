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
    public function __construct(
        private readonly ApprovalChainInvariantService $invariants,
    ) {}

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

        $steps = $chain->steps->map(fn (LeaveApprovalChainStep $step): LeaveApprovalChainStep => clone $step);
        $currentSupervisorId = $employee->currentSupervisor()?->kepala_bagian_id;
        $configuredKepalaBagian = $steps->firstWhere('step_type', 'kepala_bagian');

        // Penugasan efektif wajib memiliki slot Kepala Bagian agar snapshot tidak menyembunyikan chain rusak.
        if ($currentSupervisorId !== null) {
            if ($configuredKepalaBagian === null) {
                throw new RuntimeException('Rantai approval cuti wajib memiliki step Kepala Bagian.');
            }

            // Hanya clone untuk request baru yang diperbarui; template chain dan snapshot lama tetap utuh.
            $configuredKepalaBagian->setAttribute('approver_employee_id', $currentSupervisorId);
        } else {
            // Fail-closed tanpa syarat: tanpa Kepala Bagian efektif pada hari server, isi chain tidak boleh menjadi celah.
            // Approver basi, pemohon sendiri, maupun struktur chain lain sama-sama ditolak sebelum snapshot dibentuk.
            throw new RuntimeException('Pegawai belum memiliki Kepala Bagian efektif sehingga pengajuan cuti tidak dapat diproses.');
        }

        foreach ($steps as $step) {
            if ($step->approver_employee_id === null) {
                throw new RuntimeException("Approver {$step->role_label} belum tersedia pada konfigurasi approval cuti.");
            }
        }

        // Resolver tidak menormalkan chain lama. Bentuk dan lifecycle seluruh approver
        // diperiksa ulang setelah substitusi Kepala Bagian agar snapshot baru tidak diarahkan
        // kepada pegawai yang sudah nonaktif sejak konfigurasi terakhir disimpan.
        $this->invariants->validate(
            $steps->map(fn (LeaveApprovalChainStep $step): array => [
                'step_type' => $step->step_type,
                'role_label' => $step->role_label,
                'approver_employee_id' => $step->approver_employee_id,
                'approver_role_key' => $step->approver_role_key,
                'is_final' => (bool) $step->is_final,
            ])->all(),
        );

        return $steps->values();
    }
}
