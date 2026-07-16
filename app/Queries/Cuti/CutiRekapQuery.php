<?php

namespace App\Queries\Cuti;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CutiRekapQuery
{
    /**
     * Menyusun sumber detail kanonis tanpa mengambil kolom relasi pegawai yang sensitif dan tidak dibutuhkan.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<LeaveRequest>
     */
    public function detailRows(array $filters): Builder
    {
        $this->guardUuidFilters($filters);
        $period = $this->parsePeriod($filters['periode'] ?? null);

        return LeaveRequest::query()
            ->select([
                'id', 'employee_id', 'jenis_cuti_id', 'tanggal_mulai', 'tanggal_selesai',
                'jumlah_hari_kerja', 'status', 'created_at',
            ])
            ->with([
                'employee:id,nama_lengkap,nip,jabatan_terakhir',
                'jenisCuti:id,nama',
                'steps:id,leave_request_id,step_order,role_label,status',
            ])
            ->when($this->stringFilter($filters, 'unit'), function (Builder $query, string $unit): void {
                $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery
                    ->where('jabatan_terakhir', $unit));
            })
            ->when($this->stringFilter($filters, 'pegawai'), fn (Builder $query, string $pegawai) => $query
                ->where('employee_id', $pegawai))
            ->when($this->stringFilter($filters, 'jenis'), fn (Builder $query, string $jenis) => $query
                ->where('jenis_cuti_id', $jenis))
            ->when($period !== null, function (Builder $query) use ($period): void {
                $query->whereYear('tanggal_mulai', $period['year']);

                if ($period['month'] !== null) {
                    $query->whereMonth('tanggal_mulai', $period['month']);
                }
            })
            ->orderByDesc('tanggal_mulai')
            ->orderBy('id');
    }

    /**
     * Menyusun sumber saldo dari field materialized ledger; saldo tidak dihitung ulang dari jatah tetap.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<LeaveBalance>
     */
    public function balanceRows(array $filters): Builder
    {
        $this->guardUuidFilters($filters);
        $period = $this->parsePeriod($filters['periode'] ?? null);

        return LeaveBalance::query()
            ->select([
                'id', 'employee_id', 'tahun', 'jatah_awal', 'carry_over', 'terpakai', 'sisa',
                'sisa_n2', 'sisa_n1', 'sisa_tahun_berjalan', 'terpakai_tahun_berjalan', 'hangus',
            ])
            ->with('employee:id,nama_lengkap,nip,jabatan_terakhir')
            ->when($this->stringFilter($filters, 'unit'), function (Builder $query, string $unit): void {
                $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery
                    ->where('jabatan_terakhir', $unit));
            })
            ->when($this->stringFilter($filters, 'pegawai'), fn (Builder $query, string $pegawai) => $query
                ->where('employee_id', $pegawai))
            ->when($period !== null, fn (Builder $query) => $query->where('tahun', $period['year']))
            ->orderByDesc('tahun')
            ->orderBy('employee_id')
            ->orderBy('id');
    }

    /**
     * Pertahanan berlapis diperlukan karena query dapat dipanggil tanpa melewati FormRequest.
     *
     * @param  array<string, mixed>  $filters
     */
    private function guardUuidFilters(array $filters): void
    {
        foreach (['pegawai', 'jenis'] as $field) {
            if (! array_key_exists($field, $filters) || $filters[$field] === null) {
                continue;
            }

            $value = $filters[$field];
            if (is_array($value) || ! is_string($value) || ! Str::isUuid($value)) {
                abort(404);
            }
        }
    }

    /** @param array<string, mixed> $filters */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Format dikenal dibatasi pada tahun, tahun-bulan, atau nama bulan Indonesia dan tahun.
     * Nilai lain sengaja tidak memfilter agar kontrak lama tidak berubah diam-diam.
     *
     * @return array{year: int, month: int|null}|null
     */
    private function parsePeriod(mixed $period): ?array
    {
        if (! is_string($period)) {
            return null;
        }

        if (preg_match('/^(\d{4})$/', $period, $matches) === 1) {
            return ['year' => (int) $matches[1], 'month' => null];
        }

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $matches) === 1) {
            return ['year' => (int) $matches[1], 'month' => (int) $matches[2]];
        }

        $months = [
            'Januari' => 1,
            'Februari' => 2,
            'Maret' => 3,
            'April' => 4,
            'Mei' => 5,
            'Juni' => 6,
            'Juli' => 7,
            'Agustus' => 8,
            'September' => 9,
            'Oktober' => 10,
            'November' => 11,
            'Desember' => 12,
        ];

        if (preg_match('/^([A-Za-z]+) (\d{4})$/', $period, $matches) !== 1
            || ! array_key_exists($matches[1], $months)) {
            return null;
        }

        return ['year' => (int) $matches[2], 'month' => $months[$matches[1]]];
    }
}
