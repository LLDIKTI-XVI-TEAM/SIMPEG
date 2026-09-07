<?php

namespace App\Services\Laporan;

use App\Models\Employee;
use App\Models\EwsConfig;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use Carbon\Carbon;
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
        $statusId = $this->stringFilter($filters, 'status_pegawai_id');
        $unit = $this->stringFilter($filters, 'unit');
        $unitId = $this->stringFilter($filters, 'unit_kerja_id');
        $jenis = $this->stringFilter($filters, 'jenis');
        $jenisId = $this->stringFilter($filters, 'jenis_pegawai_id');
        $golongan = $this->stringFilter($filters, 'golongan');
        $jabatan = $this->stringFilter($filters, 'jabatan');
        $search = mb_strtolower($this->stringFilter($filters, 'search'));
        $pensiunDari = $this->stringFilter($filters, 'pensiun_dari');
        $pensiunSampai = $this->stringFilter($filters, 'pensiun_sampai');

        $bup = max(0, (int) EwsConfig::getVal('pensiun_required_age_years', 0));

        $employees = Employee::query()
            ->select([
                'id',
                'nama_lengkap',
                'nip',
                'golongan_terakhir',
                'jabatan_terakhir',
                'pendidikan_terakhir',
                'jenis_pegawai_id',
                'status_pegawai_id',
                'status_aktif',
                'tanggal_lahir',
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
            ->when($unitId !== '', function (Builder $query) use ($unitId): void {
                $query->whereHas('positionHistories', function (Builder $query) use ($unitId): void {
                    $query->where('is_latest', true)->where('unit_kerja_id', $unitId);
                });
            })
            ->when($unitId === '' && $unit !== '', function (Builder $query) use ($unit): void {
                $query->whereHas('positionHistories', function (Builder $query) use ($unit): void {
                    $query->where('is_latest', true)
                        ->whereHas('unitKerja', fn (Builder $query) => $query->where('nama', $unit));
                });
            })
            ->when($jenisId !== '', function (Builder $query) use ($jenisId): void {
                $query->where('jenis_pegawai_id', $jenisId);
            })
            ->when($jenisId === '' && $jenis !== '', function (Builder $query) use ($jenis): void {
                $query->whereHas('jenisPegawai', fn (Builder $query) => $query->where('nama', $jenis));
            })
            ->when($statusId !== '', function (Builder $query) use ($statusId): void {
                $query->where('status_pegawai_id', $statusId);
            })
            ->when($statusId === '' && ($status !== '' || $defaultToActive), function (Builder $query) use ($status): void {
                // Nilai eksplisit "all"/kosong tanpa default berarti semua status;
                // jangan tambahkan predikat apa pun.
                if ($status === 'all') {
                    return;
                }

                $resolvedStatus = $status ?: 'Aktif';

                // Default aktif memakai klasifikasi kelompok referensi — satu sumber
                // dengan daftar pegawai (isActive()/whereActiveStatus()) sehingga
                // status Aktif/khusus seperti Tugas Belajar ikut terekspor.
                if ($resolvedStatus === 'Aktif') {
                    $query->whereActiveStatus();

                    return;
                }

                $query->where(function (Builder $query) use ($resolvedStatus): void {
                    $query->where('status_aktif', $resolvedStatus)
                        ->orWhereHas('statusPegawai', fn (Builder $query) => $query->where('nama', $resolvedStatus));
                });
            })
            ->when($jabatan !== '', fn (Builder $query) => $query->where('jabatan_terakhir', $jabatan))
            ->when($pensiunDari !== '', function (Builder $query) use ($pensiunDari, $bup) {
                if ($bup > 0) {
                    $query->whereDate('tanggal_lahir', '>=', Carbon::parse($pensiunDari)->subYears($bup)->format('Y-m-d'));
                } else {
                    $query->whereDate('tanggal_pensiun', '>=', $pensiunDari);
                }
            })
            ->when($pensiunSampai !== '', function (Builder $query) use ($pensiunSampai, $bup) {
                if ($bup > 0) {
                    $query->whereDate('tanggal_lahir', '<=', Carbon::parse($pensiunSampai)->subYears($bup)->format('Y-m-d'));
                } else {
                    $query->whereDate('tanggal_pensiun', '<=', $pensiunSampai);
                }
            })
            ->get();

        $prefixField = $this->stringFilter($filters, 'prefix_field');
        $prefixValue = mb_strtolower($this->stringFilter($filters, 'prefix_value'));
        $sort = $this->stringFilter($filters, 'sort') ?: 'nama';
        $sortDir = $this->stringFilter($filters, 'sort_dir') === 'desc' ? 'desc' : 'asc';

        $rowStart = max(1, (int) ($filters['row_start'] ?? 1));
        $rowEnd = (int) ($filters['row_end'] ?? 0);

        $mapped = $employees->map(function (Employee $employee) use ($bup): array {
            $currentPosition = $employee->positionHistories->first();

            $pensiunDate = null;
            if ($bup > 0 && $employee->tanggal_lahir) {
                $pensiunDate = $employee->tanggal_lahir->copy()->addYears($bup);
            } else {
                $pensiunDate = $employee->tanggal_pensiun;
            }

            return [
                'id' => $employee->id,
                'nip' => $employee->nip,
                'nama' => $employee->nama_lengkap,
                'golongan' => $employee->golongan_terakhir ?: '-',
                'jabatan' => $employee->jabatan_terakhir ?: ($currentPosition?->nama_jabatan ?: '-'),
                'unit' => $currentPosition?->unitKerja?->nama ?: '-',
                'jenis' => $employee->jenisPegawai?->nama ?: '-',
                'status' => $employee->statusPegawai?->nama ?: ($employee->status_aktif ?: '-'),
                'pendidikan' => $employee->pendidikan_terakhir ?: '',
                'tanggal_pensiun' => $pensiunDate?->format('Y-m-d') ?: '-',
                'email' => $employee->getRawOriginal('email_pribadi') ?: '-',
                'no_hp' => $employee->no_hp ?: '-',
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
        $unitsQuery = RefUnitKerja::query()->orderBy('nama');

        return [
            'units' => $unitsQuery->pluck('nama')->all(),
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
