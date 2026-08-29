<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ApprovalChainInvariantService
{
    /**
     * Memastikan kandidat rantai dapat dipakai sebagai satu alur approval cuti yang utuh.
     *
     * Approver dikunci dalam urutan UUID yang konsisten agar status aktif atau soft delete tidak
     * berubah di antara validasi dan penyimpanan, sekaligus mengurangi risiko deadlock antarproses.
     *
     * @param  list<array{
     *     step_type:mixed,
     *     role_label:mixed,
     *     approver_employee_id:mixed,
     *     approver_role_key?:mixed,
     *     is_final:mixed
     * }>  $steps
     * @param  list<mixed>  $additionalApproverIds
     * @param  list<mixed>  $additionalEmployeeLockIds
     */
    public function validate(
        array $steps,
        array $additionalApproverIds = [],
        array $additionalEmployeeLockIds = [],
    ): void {
        $approverIds = $this->validateShape($steps);

        $this->validateApproverIds(
            [...$approverIds, ...$additionalApproverIds],
            $additionalEmployeeLockIds,
        );
    }

    /**
     * Memvalidasi sekumpulan chain terhadap set approver yang sudah dikunci global.
     *
     * Membership diperiksa tanpa mengambil lock baru agar perubahan serentak tidak membuat proses
     * kembali mengunci UUID yang lebih kecil setelah UUID yang lebih besar sudah dipegang.
     *
     * @param  list<list<array<string, mixed>>>  $chains
     * @param  list<string>  $lockedApproverIds
     */
    public function validateMany(array $chains, array $lockedApproverIds): void
    {
        $lockedApproverIdMap = array_fill_keys(
            $this->validateApproverIdFormats($lockedApproverIds),
            true,
        );

        foreach ($chains as $steps) {
            foreach ($this->validateShape($steps) as $approverId) {
                if (! isset($lockedApproverIdMap[$approverId])) {
                    throw new RuntimeException(
                        'Rantai approval aktif berubah saat konfigurasi PYBMC global diproses. Silakan ulangi.',
                    );
                }
            }
        }
    }

    /**
     * Mengunci semua approver chain aktif dan approver global dalam urutan UUID yang monoton.
     *
     * Lazy chunk menjaga penggunaan memori tetap terbatas. Set ID yang dikembalikan menjadi bukti
     * approver telah ada, aktif, belum dihapus, dan terkunci di transaksi pemanggil.
     *
     * @return list<string>
     */
    public function lockActiveChainApproversForGlobalOverride(mixed $selectedApproverId): array
    {
        $this->validateApproverIdFormats([$selectedApproverId]);

        /** @var string $selectedApproverId */
        $activeChainApproverIds = DB::table('leave_approval_chain_steps as steps')
            ->select('steps.approver_employee_id')
            ->join(
                'leave_approval_chains as chains',
                'chains.id',
                '=',
                'steps.leave_approval_chain_id',
            )
            ->where('chains.is_active', true)
            ->whereNotNull('steps.approver_employee_id');

        $lockedApproverIds = [];
        $selectedApproverFound = false;

        $approvers = Employee::query()
            ->where(function ($query) use ($activeChainApproverIds, $selectedApproverId): void {
                $query->where('employees.id', $selectedApproverId)
                    ->orWhereIn('employees.id', $activeChainApproverIds);
            })
            ->select(['employees.id', 'employees.status_pegawai_id', 'employees.status_aktif'])
            ->lockForUpdate()
            ->lazyById(100, 'employees.id', 'id');

        foreach ($approvers as $approver) {
            $this->ensureApproverIsUsable($approver);
            $lockedApproverIds[] = $approver->id;
            $selectedApproverFound = $selectedApproverFound || $approver->id === $selectedApproverId;
        }

        if (! $selectedApproverFound) {
            throw new RuntimeException('Approver pada rantai approval cuti tidak ditemukan.');
        }

        return $lockedApproverIds;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<string>
     */
    private function validateShape(array $steps): array
    {
        $finalIndexes = [];

        foreach ($steps as $index => $step) {
            if (($step['is_final'] ?? false) === true) {
                $finalIndexes[] = $index;
            }
        }

        if (count($finalIndexes) !== 1) {
            throw new RuntimeException('Rantai approval cuti wajib memiliki tepat satu approver final.');
        }

        if ($finalIndexes[0] !== array_key_last($steps)) {
            throw new RuntimeException('Approver final cuti wajib berada pada urutan terakhir.');
        }

        if (! collect($steps)->contains(
            fn (array $step): bool => ($step['step_type'] ?? null) === 'kepala_bagian',
        )) {
            throw new RuntimeException('Rantai approval cuti wajib memiliki step Kepala Bagian.');
        }

        $finalStep = $steps[$finalIndexes[0]];

        if (($finalStep['step_type'] ?? null) !== 'pybmc') {
            throw new RuntimeException('Approver final cuti wajib bertipe PYBMC.');
        }

        return $this->validateApproverIdFormats(
            array_map(
                fn (array $step): mixed => $step['approver_employee_id'] ?? null,
                $steps,
            ),
        );
    }

    /**
     * Memastikan daftar approver terpilih berbentuk UUID, masih ada, aktif, dan belum dihapus.
     *
     * @param  list<mixed>  $approverIds
     * @param  list<mixed>  $additionalEmployeeLockIds
     */
    public function validateApproverIds(array $approverIds, array $additionalEmployeeLockIds = []): void
    {
        $uniqueApproverIds = $this->validateApproverIdFormats($approverIds);
        $employeeLockIds = array_values(array_unique([
            ...$uniqueApproverIds,
            ...$this->validateEmployeeLockIdFormats($additionalEmployeeLockIds),
        ]));

        sort($employeeLockIds, SORT_STRING);

        $approvers = Employee::query()
            ->whereIn('id', $employeeLockIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'status_pegawai_id', 'status_aktif'])
            ->keyBy('id');

        foreach ($uniqueApproverIds as $approverId) {
            $approver = $approvers->get($approverId);

            if ($approver === null) {
                throw new RuntimeException('Approver pada rantai approval cuti tidak ditemukan.');
            }

            $this->ensureApproverIsUsable($approver);
        }
    }

    /**
     * @param  list<mixed>  $approverIds
     * @return list<string>
     */
    private function validateApproverIdFormats(array $approverIds): array
    {
        foreach ($approverIds as $approverId) {
            if (! is_string($approverId) || trim($approverId) === '') {
                throw new RuntimeException('Setiap langkah rantai approval cuti wajib memiliki ID approver.');
            }

            if (! Str::isUuid($approverId)) {
                throw new RuntimeException('ID approver pada rantai approval cuti wajib berupa UUID yang valid.');
            }
        }

        /** @var list<string> $uniqueApproverIds */
        $uniqueApproverIds = array_values(array_unique($approverIds));

        return $uniqueApproverIds;
    }

    /**
     * @param  list<mixed>  $employeeLockIds
     * @return list<string>
     */
    private function validateEmployeeLockIdFormats(array $employeeLockIds): array
    {
        foreach ($employeeLockIds as $employeeLockId) {
            if (! is_string($employeeLockId) || ! Str::isUuid($employeeLockId)) {
                throw new RuntimeException('ID pegawai yang akan dikunci wajib berupa UUID yang valid.');
            }
        }

        /** @var list<string> $validatedEmployeeLockIds */
        $validatedEmployeeLockIds = array_values(array_unique($employeeLockIds));

        return $validatedEmployeeLockIds;
    }

    private function ensureApproverIsUsable(Employee $approver): void
    {
        // Klasifikasi aktif dari kelompok referensi — satu sumber dengan isActive()
        // sehingga Tugas Belajar (Aktif/khusus) tetap sah sebagai approver.
        if (! $approver->isActive()) {
            throw new RuntimeException('Approver pada rantai approval cuti wajib berstatus Aktif.');
        }
    }
}
