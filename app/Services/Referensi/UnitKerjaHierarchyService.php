<?php

namespace App\Services\Referensi;

use App\Models\RefUnitKerja;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class UnitKerjaHierarchyService
{
    private const LOCK_NAME = 'simpeg.ref_unit_kerja';

    /**
     * Menserialkan mutasi hierarki selama transaksi berjalan. Mutex diperlukan
     * karena pengecekan parent dan perubahan status menyentuh beberapa baris
     * yang tidak dapat dilindungi oleh satu constraint database.
     */
    public function lockForMutation(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Lock hierarki unit kerja harus diperoleh di dalam transaksi.');
        }

        // SQLite menserialkan writer secara global. PostgreSQL production
        // memakai advisory transaction lock agar semua Action berbagi mutex.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select(
                'select pg_advisory_xact_lock(hashtextextended(?, 0))',
                [self::LOCK_NAME],
            );
        }
    }

    /**
     * Memeriksa ulang keunikan nama setelah mutex diperoleh. Validasi request
     * dapat menjadi usang saat dua mutasi menunggu lock yang sama.
     *
     * @throws ValidationException bila nama sudah digunakan unit lain
     */
    public function ensureNameAvailable(string $name, ?string $unitId = null): void
    {
        $query = RefUnitKerja::query()->where('nama', $name);

        if ($unitId !== null) {
            $query->whereKeyNot($unitId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'nama' => 'Nama unit kerja sudah digunakan.',
            ]);
        }
    }

    /**
     * Mengembalikan pesan pelanggaran relasi parent, atau null bila rantai
     * bersih, memenuhi kebijakan status, dan tidak kembali ke unit yang diubah.
     */
    public function parentValidationMessage(
        string $candidateParentId,
        ?string $unitId,
        bool $allowInactiveChain,
    ): ?string {
        if ($unitId !== null && $candidateParentId === $unitId) {
            return 'Unit induk tidak boleh unit itu sendiri.';
        }

        $visited = [];
        $cursor = $candidateParentId;

        while ($cursor !== '') {
            if (isset($visited[$cursor])) {
                return 'Rantai induk unit tujuan sudah membentuk lingkaran. Perbaiki struktur unit tersebut lebih dulu.';
            }

            $visited[$cursor] = true;

            /** @var RefUnitKerja|null $parent */
            $parent = RefUnitKerja::query()->find($cursor);

            if ($parent === null) {
                return 'Rantai induk unit tujuan tidak lengkap. Perbaiki struktur unit tersebut lebih dulu.';
            }

            if (! $allowInactiveChain && ! $parent->is_active) {
                return 'Unit induk dan seluruh rantai di atasnya harus aktif.';
            }

            if ($parent->parent_id === null) {
                return null;
            }

            if ($unitId !== null && $parent->parent_id === $unitId) {
                return 'Unit induk tidak boleh diambil dari sub-unit di bawahnya.';
            }

            $cursor = $parent->parent_id;
        }

        return null;
    }

    /**
     * Menolak parent yang berubah setelah validasi request. Pemeriksaan ini
     * dipanggil ulang setelah mutex diperoleh agar keputusan dan write memakai
     * snapshot hierarki yang sama.
     *
     * @throws ValidationException bila parent melanggar invariant hierarki
     */
    public function ensureParentAllowed(
        string $candidateParentId,
        ?string $unitId,
        bool $allowInactiveChain,
    ): void {
        $message = $this->parentValidationMessage(
            $candidateParentId,
            $unitId,
            $allowInactiveChain,
        );

        if ($message !== null) {
            throw ValidationException::withMessages(['parent_id' => $message]);
        }
    }

    public function levelFromParent(?string $parentId): int
    {
        if ($parentId === null) {
            return 0;
        }

        return (int) RefUnitKerja::query()
            ->whereKey($parentId)
            ->value('level') + 1;
    }
}
