<?php

namespace App\Actions\Cuti;

use App\Models\RefJenisCuti;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Menyusun halaman cuti Kepala Bagian dengan daftar terpagasi dan opsi filter yang terbatas.
 */
class ShowKepalaBagianLeaveIndexAction
{
    private const MAX_LEAVE_TYPE_OPTIONS = 100;

    public function __construct(private readonly ListKepalaBagianLeavesAction $leaves) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(User $actor, array $filters): array
    {
        $filters = $this->normalizedFilters($filters);

        return [
            'leaves' => $this->leaves->execute($actor, $filters),
            'filters' => $filters,
            'jenisCutiOptions' => $this->leaveTypeOptions(
                is_string($filters['jenis_cuti_id'] ?? null) ? $filters['jenis_cuti_id'] : null,
            ),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function normalizedFilters(array $filters): array
    {
        if (! array_key_exists('status', $filters)) {
            $filters['status'] = 'menunggu_approval';
        } elseif ($filters['status'] === 'all') {
            $filters['status'] = null;
        }

        return $filters;
    }

    /**
     * Pilihan aktif ditempatkan pertama agar tetap tersedia ketika katalog melebihi batas kontrol.
     *
     * @return Collection<int, RefJenisCuti>
     */
    private function leaveTypeOptions(?string $selectedId): Collection
    {
        return RefJenisCuti::query()
            ->select(['id', 'nama'])
            ->when($selectedId, fn (Builder $query, string $id): Builder => $query
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$id]))
            ->orderBy('nama')
            ->orderBy('id')
            ->limit(self::MAX_LEAVE_TYPE_OPTIONS)
            ->get();
    }
}
