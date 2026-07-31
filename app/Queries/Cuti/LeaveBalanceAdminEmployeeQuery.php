<?php

namespace App\Queries\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalanceLedger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class LeaveBalanceAdminEmployeeQuery
{
    /**
     * Membatasi populasi pada pegawai yang belum dihapus dan mencocokkan pencarian tanpa membedakan kapitalisasi.
     *
     * @return Builder<Employee>
     */
    private function populationQuery(string $search): Builder
    {
        $query = Employee::query();
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        $term = '%'.mb_strtolower($search).'%';

        return $query->where(function (Builder $query) use ($term): void {
            $query
                ->whereRaw('lower(nama_lengkap) like ?', [$term])
                ->orWhereRaw('lower(nip) like ?', [$term]);
        });
    }

    /**
     * Memilih data minimum pegawai serta penanda keberadaan saldo dan event pembukaan pada periode yang sama.
     *
     * @return Builder<Employee>
     */
    private function rowsQuery(int $periode, string $search): Builder
    {
        return $this->populationQuery($search)
            ->select(['employees.id', 'employees.nama_lengkap', 'employees.nip'])
            ->selectRaw(
                'exists (select 1 from leave_balances where leave_balances.employee_id = employees.id and leave_balances.tahun = ?) as has_balance_row',
                [$periode],
            )
            ->selectRaw(
                'exists (select 1 from leave_balance_ledger where leave_balance_ledger.employee_id = employees.id and leave_balance_ledger.tahun = ? and leave_balance_ledger.event_type = ?) as has_opening_event_same_year',
                [$periode, LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET],
            );
    }

    /**
     * Menyusun EXISTS terkorelasi agar status pendaftaran hanya mengakui event pembukaan pada tahun pilihan.
     */
    private function openingEventExists(QueryBuilder $query, int $periode): void
    {
        $query
            ->selectRaw('1')
            ->from('leave_balance_ledger')
            ->whereColumn('leave_balance_ledger.employee_id', 'employees.id')
            ->where('leave_balance_ledger.tahun', $periode)
            ->where('leave_balance_ledger.event_type', LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET);
    }

    /**
     * Status perlu tindakan berarti event pembukaan belum ada; semua pegawai sengaja tanpa predikat status.
     *
     * @param  Builder<Employee>  $query
     */
    private function applyStatus(Builder $query, string $status, int $periode): void
    {
        if ($status === 'perlu_tindakan') {
            $query->whereNotExists(
                fn (QueryBuilder $query) => $this->openingEventExists($query, $periode),
            );
        }

        if ($status === 'sudah_terdaftar') {
            $query->whereExists(
                fn (QueryBuilder $query) => $this->openingEventExists($query, $periode),
            );
        }
    }

    /**
     * Menghasilkan antrean pegawai terurut dan terpagasi tanpa memuat relasi atau seluruh populasi.
     *
     * @return LengthAwarePaginator<int, array{
     *     employee_id: string,
     *     nama_lengkap: string,
     *     nip: string,
     *     periode: int,
     *     status_code: string,
     *     has_balance_row: bool,
     *     has_opening_event_same_year: bool
     * }>
     */
    public function employeeRows(int $periode, string $status, string $search): LengthAwarePaginator
    {
        $query = $this->rowsQuery($periode, trim($search));
        $this->applyStatus($query, $status, $periode);

        return $query
            ->orderBy('employees.nama_lengkap')
            ->orderBy('employees.id')
            ->paginate(10, ['*'], 'page_pegawai')
            ->withQueryString()
            ->through(function (Employee $employee) use ($periode): array {
                $hasBalanceRow = (bool) $employee->getAttribute('has_balance_row');
                $hasOpeningEventSameYear = (bool) $employee->getAttribute('has_opening_event_same_year');

                $statusCode = match (true) {
                    $hasOpeningEventSameYear => 'saldo_awal_tercatat',
                    $hasBalanceRow => 'pembukaan_belum_tercatat',
                    default => 'saldo_belum_tersedia',
                };

                return [
                    'employee_id' => (string) $employee->id,
                    'nama_lengkap' => (string) $employee->nama_lengkap,
                    'nip' => (string) $employee->nip,
                    'periode' => $periode,
                    'status_code' => $statusCode,
                    'has_balance_row' => $hasBalanceRow,
                    'has_opening_event_same_year' => $hasOpeningEventSameYear,
                ];
            });
    }

    /**
     * Menghitung status langsung di basis data; filter status aktif tidak termasuk kontrak populasi administrasi.
     *
     * @return array{perlu_tindakan: int, sudah_terdaftar: int, semua_pegawai: int}
     */
    public function statusCounts(int $periode, string $search): array
    {
        $population = $this->populationQuery($search);
        $all = (clone $population)->count();
        $registeredQuery = clone $population;
        $this->applyStatus($registeredQuery, 'sudah_terdaftar', $periode);
        $registered = $registeredQuery->count();

        return [
            'perlu_tindakan' => $all - $registered,
            'sudah_terdaftar' => $registered,
            'semua_pegawai' => $all,
        ];
    }
}
