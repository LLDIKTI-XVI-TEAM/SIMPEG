<?php

namespace App\Services\Laporan;

use App\Models\Employee;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EmployeeExportDataService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, string>>
     */
    public function rows(array $filters, bool $defaultToActive = true): Collection
    {
        $status = $this->stringFilter($filters, 'status');
        $unit = $this->stringFilter($filters, 'unit');
        $jenis = $this->stringFilter($filters, 'jenis');
        $golongan = $this->stringFilter($filters, 'golongan');
        $jabatan = $this->stringFilter($filters, 'jabatan');
        $search = mb_strtolower($this->stringFilter($filters, 'search'));
        $pensiunDari = $this->stringFilter($filters, 'pensiun_dari');
        $pensiunSampai = $this->stringFilter($filters, 'pensiun_sampai');

        $employees = Employee::query()
            ->select([
                'id',
                'nama_lengkap',
                'nip',
                'golongan_terakhir',
                'jabatan_terakhir',
                'jenis_pegawai_id',
                'status_pegawai_id',
                'status_aktif',
                'pendidikan_terakhir',
                'tanggal_pensiun',
            ])
            ->with([
                'jenisPegawai:id,nama',
                'statusPegawai:id,nama',
                'positionHistories' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'unit_kerja_id', 'nama_jabatan', 'is_latest', 'tmt_jabatan'])
                    ->with('unitKerja:id,nama')
                    ->where('is_latest', true)
                    ->orderByDesc('tmt_jabatan'),
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereRaw('LOWER(nama_lengkap) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(nip) LIKE ?', ["%{$search}%"]);
                });
            })
            ->when($golongan !== '', function (Builder $query) use ($golongan): void {
                $query->where(function (Builder $query) use ($golongan): void {
                    $query->where('golongan_terakhir', $golongan)
                        ->orWhere('golongan_terakhir', 'like', $golongan.'/%');
                });
            })
            ->when($unit !== '', function (Builder $query) use ($unit): void {
                $query->whereHas('positionHistories', function (Builder $query) use ($unit): void {
                    $query->where('is_latest', true)
                        ->whereHas('unitKerja', fn (Builder $query) => $query->where('nama', $unit));
                });
            })
            ->when($jenis !== '', function (Builder $query) use ($jenis): void {
                $query->whereHas('jenisPegawai', fn (Builder $query) => $query->where('nama', $jenis));
            })
            ->when($status !== '' || $defaultToActive, function (Builder $query) use ($status): void {
                $resolvedStatus = $status ?: 'Aktif';

                $query->where(function (Builder $query) use ($resolvedStatus): void {
                    $query->where('status_aktif', $resolvedStatus)
                        ->orWhereHas('statusPegawai', fn (Builder $query) => $query->where('nama', $resolvedStatus));
                });
            })
            ->when($jabatan !== '', fn (Builder $query) => $query->where('jabatan_terakhir', $jabatan))
            ->when($pensiunDari !== '', fn (Builder $query) => $query->whereDate('tanggal_pensiun', '>=', $pensiunDari))
            ->when($pensiunSampai !== '', fn (Builder $query) => $query->whereDate('tanggal_pensiun', '<=', $pensiunSampai))
            ->get();

        $prefixField = $this->stringFilter($filters, 'prefix_field');
        $prefixValue = mb_strtolower($this->stringFilter($filters, 'prefix_value'));
        $sort = $this->stringFilter($filters, 'sort') ?: 'nama';
        $sortDir = $this->stringFilter($filters, 'sort_dir') === 'desc' ? 'desc' : 'asc';

        $rowStart = max(1, (int) ($filters['row_start'] ?? 1));
        $rowEnd = (int) ($filters['row_end'] ?? 0);

        $mapped = $employees->map(function (Employee $employee): array {
            $currentPosition = $employee->positionHistories->first();

            return [
                'id' => $employee->id,
                'nip' => $employee->nip,
                'nama' => $employee->nama_lengkap,
                'golongan' => $employee->golongan_terakhir ?: '-',
                'jabatan' => $employee->jabatan_terakhir ?: ($currentPosition?->nama_jabatan ?: '-'),
                'unit' => $currentPosition?->unitKerja?->nama ?: '-',
                'jenis' => $employee->jenisPegawai?->nama ?: '-',
                'status' => $employee->statusPegawai?->nama ?: ($employee->status_aktif ?: '-'),
                'pendidikan' => $employee->pendidikan_terakhir ?: '-',
                'tanggal_pensiun' => $employee->tanggal_pensiun?->format('Y-m-d') ?: '-',
            ];
        });

        if ($prefixField !== '' && $prefixValue !== '') {
            $mapped = $mapped->filter(function (array $row) use ($prefixField, $prefixValue): bool {
                $val = mb_strtolower((string) ($row[$prefixField] ?? ''));

                return str_starts_with($val, $prefixValue);
            });
        }

        $sorted = $this->sortRows($mapped, $sort, $sortDir);

        if ($rowEnd > 0) {
            $length = $rowEnd - $rowStart + 1;

            return $sorted->slice($rowStart - 1, max(0, $length))->values();
        }

        return $sorted->slice($rowStart - 1)->values();
    }

    /**
     * @return array{units: list<string>, golongan: list<string>, jenis: list<string>, status: list<string>, jabatan: list<string>}
     */
    public function filterOptions(): array
    {
        return [
            'units' => RefUnitKerja::query()->orderBy('nama')->pluck('nama')->all(),
            'golongan' => RefGolongan::query()->orderBy('urutan')->pluck('kode')->all(),
            'jenis' => RefJenisPegawai::query()->orderBy('nama')->pluck('nama')->all(),
            'status' => RefStatusPegawai::query()->orderBy('nama')->pluck('nama')->all(),
            'jabatan' => RefJabatan::query()->orderBy('nama')->pluck('nama')->all(),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function stringFilter(array $filters, string $key): string
    {
        return trim((string) ($filters[$key] ?? ''));
    }

    /**
     * @param  Collection<int, array<string, string>>  $rows
     * @return Collection<int, array<string, string>>
     */
    private function sortRows(Collection $rows, string $sort, string $sortDir = 'asc'): Collection
    {
        $golonganOrder = [
            'IV/e' => 1, 'IV/d' => 2, 'IV/c' => 3, 'IV/b' => 4, 'IV/a' => 5,
            'III/d' => 6, 'III/c' => 7, 'III/b' => 8, 'III/a' => 9,
            'II/d' => 10, 'II/c' => 11, 'II/b' => 12, 'II/a' => 13,
            'I/d' => 14, 'I/c' => 15, 'I/b' => 16, 'I/a' => 17,
        ];

        $sorted = match ($sort) {
            'nip' => $rows->sortBy('nip', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'golongan' => $rows->sortBy(
                fn (array $row): int => $golonganOrder[$row['golongan']] ?? PHP_INT_MAX
            )->values(),
            default => $rows->sortBy('nama', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        };

        if ($sortDir === 'desc') {
            $sorted = $sorted->reverse()->values();
        }

        return $sorted;
    }
}
