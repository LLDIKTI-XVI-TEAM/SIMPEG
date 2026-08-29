<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use Illuminate\Support\Collection;

class LookupEmployeesAction
{
    private const RESULT_LIMIT = 15;

    /**
     * Mengembalikan identitas minimum agar autocomplete tidak memuat data pegawai sensitif.
     *
     * @return Collection<int, array{id: string, nama_lengkap: string, nip: string}>
     */
    public function execute(string $query): Collection
    {
        $keyword = '%'.mb_strtolower(trim($query)).'%';

        return Employee::query()
            ->select(['id', 'nama_lengkap', 'nip'])
            ->whereActiveStatus()
            ->where(function ($employeeQuery) use ($keyword): void {
                $employeeQuery->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                    ->orWhereRaw('lower(nip) like ?', [$keyword]);
            })
            ->orderBy('nama_lengkap')
            ->limit(self::RESULT_LIMIT)
            ->get()
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'nama_lengkap' => $employee->nama_lengkap,
                'nip' => $employee->nip,
            ]);
    }
}
