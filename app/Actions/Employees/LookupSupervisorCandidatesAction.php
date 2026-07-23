<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use Illuminate\Support\Collection;

class LookupSupervisorCandidatesAction
{
    private const RESULT_LIMIT = 15;

    /**
     * Mengambil kandidat penugasan minimum, tidak memfilter status, role, atau unit agar sesuai aturan penugasan.
     *
     * @return Collection<int, array{id: string, nama_lengkap: string, nip: string}>
     */
    public function execute(string $query, string $excludedEmployeeId): Collection
    {
        $keyword = '%'.mb_strtolower(trim($query)).'%';

        return Employee::query()
            ->select(['id', 'nama_lengkap', 'nip'])
            ->whereKeyNot($excludedEmployeeId)
            ->where(function ($employeeQuery) use ($keyword): void {
                $employeeQuery->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                    ->orWhereRaw('lower(nip) like ?', [$keyword]);
            })
            ->orderBy('nama_lengkap')
            ->orderBy('nip')
            ->orderBy('id')
            ->limit(self::RESULT_LIMIT)
            ->get()
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'nama_lengkap' => $employee->nama_lengkap,
                'nip' => $employee->nip,
            ]);
    }
}
