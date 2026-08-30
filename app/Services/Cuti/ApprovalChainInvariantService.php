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
     * Approver dikunci dalam urutan UUID yang konsisten agar status aktif atau ketersediaan ID tidak
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
        $this->assertCanonicalShape($steps);
        $approverIds = $this->approverIdsFromSteps($steps);

        $this->validateApproverIds(
            [...$approverIds, ...$additionalApproverIds],
            $additionalEmployeeLockIds,
        );
    }

    /**
     * Memvalidasi bentuk dan approver chain, lalu memastikan Kepala Bagian berasal dari penugasan efektif.
     * Target ikut dikunci bersama approver sebelum penugasan dibaca agar urutan lock tetap deterministik.
     *
     * @param  array<array-key, array<string, mixed>>  $steps
     * @param  list<mixed>  $additionalApproverIds
     * @param  list<mixed>  $additionalEmployeeLockIds
     */
    public function validateForEmployee(
        Employee $employee,
        array $steps,
        array $additionalApproverIds = [],
        array $additionalEmployeeLockIds = [],
    ): void {
        $this->validate(
            $steps,
            $additionalApproverIds,
            [...$additionalEmployeeLockIds, $employee->id],
        );

        $effectiveKepalaBagianId = $employee->currentSupervisor()?->kepala_bagian_id;
        $configuredKepalaBagianId = collect($steps)
            ->firstWhere('step_type', 'kepala_bagian')['approver_employee_id'] ?? null;

        if ($effectiveKepalaBagianId === null) {
            throw new RuntimeException('Pegawai belum memiliki Kepala Bagian efektif.');
        }

        if ($configuredKepalaBagianId !== $effectiveKepalaBagianId) {
            throw new RuntimeException(
                'Approver pada tahap Kepala Bagian harus sama dengan Kepala Bagian efektif pegawai.',
            );
        }
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
            $this->assertCanonicalShape($steps);

            foreach ($this->approverIdsFromSteps($steps) as $approverId) {
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
     * approver telah ada, aktif, tersedia, dan terkunci di transaksi pemanggil.
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
     * Memastikan bentuk rantai mengikuti urutan kewenangan tanpa membaca database.
     *
     * @param  array<array-key, array<string, mixed>>  $steps
     */
    public function assertCanonicalShape(array $steps): void
    {
        if (! array_is_list($steps)) {
            throw new RuntimeException('Langkah rantai approval cuti wajib berupa daftar berurutan.');
        }

        $stepTypes = array_column($steps, 'step_type');

        foreach ($stepTypes as $stepType) {
            if (! in_array($stepType, ['verifier', 'kepala_bagian', 'pybmc'], true)) {
                throw new RuntimeException('Tipe langkah rantai approval cuti tidak valid.');
            }
        }

        $kepalaBagianIndexes = array_keys($stepTypes, 'kepala_bagian', true);
        $verifierIndexes = array_keys($stepTypes, 'verifier', true);
        $pybmcIndexes = array_keys($stepTypes, 'pybmc', true);

        if (count($kepalaBagianIndexes) !== 1) {
            throw new RuntimeException('Rantai approval cuti wajib memiliki tepat satu step Kepala Bagian.');
        }

        if (count($pybmcIndexes) !== 1) {
            throw new RuntimeException('Rantai approval cuti wajib memiliki tepat satu step PYBMC.');
        }

        $kepalaBagianIndex = $kepalaBagianIndexes[0];

        foreach ($verifierIndexes as $verifierIndex) {
            if ($verifierIndex > $kepalaBagianIndex) {
                throw new RuntimeException('Semua Verifikator harus ditempatkan sebelum Kepala Bagian.');
            }
        }

        $lastIndex = count($steps) - 1;

        if ($pybmcIndexes[0] !== $lastIndex) {
            throw new RuntimeException('Step PYBMC cuti wajib berada pada urutan terakhir.');
        }

        $finalIndexes = [];

        foreach ($steps as $index => $step) {
            if (($step['is_final'] ?? false) === true) {
                $finalIndexes[] = $index;
            }
        }

        if (count($finalIndexes) !== 1) {
            throw new RuntimeException('Rantai approval cuti wajib memiliki tepat satu approver final.');
        }

        if ($finalIndexes[0] !== $lastIndex) {
            throw new RuntimeException('Approver final cuti wajib berada pada urutan terakhir.');
        }

        $finalStep = $steps[$finalIndexes[0]];

        if (($finalStep['step_type'] ?? null) !== 'pybmc') {
            throw new RuntimeException('Approver final cuti wajib bertipe PYBMC.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<string>
     */
    private function approverIdsFromSteps(array $steps): array
    {
        return $this->validateApproverIdFormats(
            array_map(
                fn (array $step): mixed => $step['approver_employee_id'] ?? null,
                $steps,
            ),
        );
    }

    /**
     * Memastikan daftar approver terpilih berbentuk UUID, masih tersedia, dan aktif.
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
            ->with('statusPegawai:id,kelompok')
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
