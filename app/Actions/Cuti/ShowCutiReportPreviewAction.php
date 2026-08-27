<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Queries\Cuti\CutiRekapQuery;

class ShowCutiReportPreviewAction
{
    public function __construct(
        private readonly CutiRekapQuery $rekapQuery,
    ) {}

    /**
     * Memakai query detail kanonis agar preview dan ekspor tidak berbeda hasil atau urutannya.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters): array
    {
        $periode = $this->stringFilter($filters, 'periode');
        $unit = $this->stringFilter($filters, 'unit');
        $pegawaiId = $this->stringFilter($filters, 'pegawai');
        $jenisId = $this->stringFilter($filters, 'jenis');
        $rows = $this->rekapQuery->paginateDetailRows($filters, 15, 'page');
        $selectedEmployee = $pegawaiId === null
            ? null
            : Employee::query()->select(['id', 'nama_lengkap', 'nip'])->find($pegawaiId);
        $unitOptions = $this->rekapQuery->unitOptions($unit);
        $jenisOptions = $this->rekapQuery->leaveTypeOptions($jenisId);

        return compact(
            'rows', 'filters', 'periode', 'unit', 'pegawaiId', 'jenisId',
            'selectedEmployee', 'unitOptions', 'jenisOptions',
        );
    }

    /** @param array<string, mixed> $filters */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
