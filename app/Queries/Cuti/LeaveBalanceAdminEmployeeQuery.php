<?php

namespace App\Queries\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveUsageRecord;
use App\Models\User;
use App\Services\Employees\EmployeeDashboardScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

class LeaveBalanceAdminEmployeeQuery
{
    public function __construct(private readonly EmployeeDashboardScopeService $employeeScope) {}

    /**
     * Mengambil identitas pegawai dan projection tahun aktif dalam satu query detail.
     *
     * @return array{employee:?Employee,balance:?LeaveBalance,rule5Active:bool,activeReserved:int,dutyProtected:int}
     */
    public function selectedWorkspace(string $employeeId, int $year, User $actor): array
    {
        $activeReservations = LeaveBalanceReservationEvent::query()
            ->selectRaw('COALESCE(SUM(leave_balance_reservation_events.amount), 0)')
            ->forActiveRequests()
            ->whereColumn('leave_balance_reservation_events.employee_id', 'employees.id')
            ->where('leave_balance_reservation_events.tahun', $year);

        // Hanya tiga key ledger kanonis yang dijumlahkan; agregasi tetap berada dalam query workspace
        // agar halaman tidak memuat metadata privat atau menambah query per pegawai.
        $protectedBucketSql = collect(['n2', 'n1', 'current'])
            ->map(fn (string $bucket): string => "GREATEST(COALESCE((leave_balance_ledger.metadata->'protected_allocations'->>'{$bucket}')::integer, 0), 0)")
            ->implode(' + ');
        $dutyProtectedQuery = LeaveBalanceLedger::query()
            ->selectRaw("COALESCE(SUM({$protectedBucketSql}), 0)")
            ->whereColumn('leave_balance_ledger.employee_id', 'employees.id')
            ->where('leave_balance_ledger.source_year', $year)
            ->where('leave_balance_ledger.event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED);

        $employee = $this->employeeScope->for($actor)
            ->leftJoin('leave_balances as selected_balance', function (JoinClause $join) use ($year): void {
                $join->on('selected_balance.employee_id', '=', 'employees.id')
                    ->where('selected_balance.tahun', $year);
            })
            ->select([
                'employees.id',
                'employees.nama_lengkap',
                'employees.nip',
                'selected_balance.id as selected_balance_id',
                'selected_balance.employee_id as selected_balance_employee_id',
                'selected_balance.tahun as selected_balance_tahun',
                'selected_balance.jatah_awal as selected_balance_jatah_awal',
                'selected_balance.carry_over as selected_balance_carry_over',
                'selected_balance.terpakai as selected_balance_terpakai',
                'selected_balance.sisa as selected_balance_sisa',
                'selected_balance.sisa_n2 as selected_balance_sisa_n2',
                'selected_balance.sisa_n1 as selected_balance_sisa_n1',
                'selected_balance.sisa_tahun_berjalan as selected_balance_sisa_tahun_berjalan',
                'selected_balance.terpakai_tahun_berjalan as selected_balance_terpakai_tahun_berjalan',
                'selected_balance.hangus as selected_balance_hangus',
            ])
            ->selectRaw(
                'exists (select 1 from leave_usage_records join ref_jenis_cuti on ref_jenis_cuti.id = leave_usage_records.leave_type_id where leave_usage_records.employee_id = employees.id and leave_usage_records.usage_year = ? and leave_usage_records.record_status = ? and leave_usage_records.workdays > 0 and ref_jenis_cuti.code = ?) as selected_rule5_active',
                [$year, LeaveUsageRecord::STATUS_ACTIVE, 'besar'],
            )
            ->selectSub($activeReservations, 'selected_active_reserved')
            ->selectSub($dutyProtectedQuery, 'selected_duty_protected')
            ->where('employees.id', $employeeId)
            ->first();

        if (! $employee instanceof Employee) {
            return [
                'employee' => null,
                'balance' => null,
                'rule5Active' => false,
                'activeReserved' => 0,
                'dutyProtected' => 0,
            ];
        }

        $rule5Active = filter_var($employee->getAttribute('selected_rule5_active'), FILTER_VALIDATE_BOOL);
        $activeReserved = (int) $employee->getAttribute('selected_active_reserved');
        $dutyProtected = (int) $employee->getAttribute('selected_duty_protected');
        $balanceId = $employee->getAttribute('selected_balance_id');
        if (! is_string($balanceId)) {
            return compact('employee', 'rule5Active', 'activeReserved', 'dutyProtected') + ['balance' => null];
        }

        $balance = (new LeaveBalance)->forceFill([
            'id' => $balanceId,
            'employee_id' => $employee->getAttribute('selected_balance_employee_id'),
            'tahun' => $employee->getAttribute('selected_balance_tahun'),
            'jatah_awal' => $employee->getAttribute('selected_balance_jatah_awal'),
            'carry_over' => $employee->getAttribute('selected_balance_carry_over'),
            'terpakai' => $employee->getAttribute('selected_balance_terpakai'),
            'sisa' => $employee->getAttribute('selected_balance_sisa'),
            'sisa_n2' => $employee->getAttribute('selected_balance_sisa_n2'),
            'sisa_n1' => $employee->getAttribute('selected_balance_sisa_n1'),
            'sisa_tahun_berjalan' => $employee->getAttribute('selected_balance_sisa_tahun_berjalan'),
            'terpakai_tahun_berjalan' => $employee->getAttribute('selected_balance_terpakai_tahun_berjalan'),
            'hangus' => $employee->getAttribute('selected_balance_hangus'),
        ]);
        $balance->exists = true;

        return compact('employee', 'balance', 'rule5Active', 'activeReserved', 'dutyProtected');
    }

    /**
     * Membatasi populasi pada pegawai yang belum dihapus dan mencocokkan pencarian tanpa membedakan kapitalisasi.
     *
     * @return Builder<Employee>
     */
    private function populationQuery(string $search, User $actor): Builder
    {
        $query = $this->employeeScope->for($actor);
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
     * Memilih data minimum pegawai serta penanda fakta pemakaian aktif pada periode yang sama.
     *
     * @return Builder<Employee>
     */
    private function rowsQuery(int $periode, string $search, User $actor): Builder
    {
        return $this->populationQuery($search, $actor)
            ->select(['employees.id', 'employees.nama_lengkap', 'employees.nip'])
            ->selectRaw(
                'exists (select 1 from leave_usage_records where leave_usage_records.employee_id = employees.id and leave_usage_records.usage_year = ? and leave_usage_records.record_status = ?) as has_active_usage',
                [$periode, LeaveUsageRecord::STATUS_ACTIVE],
            );
    }

    /**
     * Menyusun EXISTS terkorelasi dari fakta pemakaian aktif pada tahun berjalan.
     */
    private function activeUsageExists(QueryBuilder $query, int $periode): void
    {
        $query
            ->selectRaw('1')
            ->from('leave_usage_records')
            ->whereColumn('leave_usage_records.employee_id', 'employees.id')
            ->where('leave_usage_records.usage_year', $periode)
            ->where('leave_usage_records.record_status', LeaveUsageRecord::STATUS_ACTIVE);
    }

    /**
     * Status hanya menyaring read model fakta pemakaian; tidak memicu kewajiban input agregat.
     *
     * @param  Builder<Employee>  $query
     */
    private function applyStatus(Builder $query, string $status, int $periode): void
    {
        if ($status === 'perlu_tindakan') {
            $query->whereNotExists(
                fn (QueryBuilder $query) => $this->activeUsageExists($query, $periode),
            );
        }

        if ($status === 'sudah_terdaftar') {
            $query->whereExists(
                fn (QueryBuilder $query) => $this->activeUsageExists($query, $periode),
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
     *     has_active_usage: bool
     * }>
     */
    public function employeeRows(int $periode, string $status, string $search, User $actor, int $perPage = 10): LengthAwarePaginator
    {
        $query = $this->rowsQuery($periode, trim($search), $actor);
        $this->applyStatus($query, $status, $periode);

        return $query
            ->orderBy('employees.nama_lengkap')
            ->orderBy('employees.id')
            ->paginate($perPage, ['*'], 'page_pegawai')
            ->withQueryString()
            ->through(function (Employee $employee) use ($periode): array {
                $hasActiveUsage = (bool) $employee->getAttribute('has_active_usage');

                return [
                    'employee_id' => (string) $employee->id,
                    'nama_lengkap' => (string) $employee->nama_lengkap,
                    'nip' => (string) $employee->nip,
                    'periode' => $periode,
                    'status_code' => $hasActiveUsage ? 'fakta_aktif' : 'belum_ada_fakta',
                    'has_active_usage' => $hasActiveUsage,
                ];
            });
    }

    /**
     * Menghitung status langsung di basis data; filter status aktif tidak termasuk kontrak populasi administrasi.
     *
     * @return array{perlu_tindakan: int, sudah_terdaftar: int, semua_pegawai: int}
     */
    public function statusCounts(int $periode, string $search, User $actor): array
    {
        // Satu agregasi menjaga hitungan status tidak menambah query kedua pada halaman administrasi.
        $counts = $this->populationQuery($search, $actor)
            ->selectRaw(
                'COUNT(*) AS total_count, COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM leave_usage_records WHERE leave_usage_records.employee_id = employees.id AND leave_usage_records.usage_year = ? AND leave_usage_records.record_status = ?) THEN 1 ELSE 0 END), 0) AS registered_count',
                [$periode, LeaveUsageRecord::STATUS_ACTIVE],
            )
            ->toBase()
            ->first();
        $all = (int) ($counts?->total_count ?? 0);
        $registered = (int) ($counts?->registered_count ?? 0);

        return [
            'perlu_tindakan' => $all - $registered,
            'sudah_terdaftar' => $registered,
            'semua_pegawai' => $all,
        ];
    }
}
