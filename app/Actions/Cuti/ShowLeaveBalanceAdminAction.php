<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalanceLedger;
use App\Queries\Cuti\CutiRekapQuery;
use App\Queries\Cuti\LeaveBalanceAdminEmployeeQuery;

class ShowLeaveBalanceAdminAction
{
    public function __construct(
        private readonly CutiRekapQuery $rekapQuery,
        private readonly LeaveBalanceAdminEmployeeQuery $employeeQuery,
    ) {}

    /**
     * Menyusun data administrasi saldo dari sumber rekap kanonis agar saldo dan ledger tetap konsisten.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters): array
    {
        // Tahun administrasi berasal dari waktu aplikasi, bukan parameter klien yang dapat dimanipulasi.
        $periode = (string) now(config('app.timezone'))->year;
        $filters['periode'] = $periode;
        $pegawaiId = $this->stringFilter($filters, 'pegawai');
        $status = $this->stringFilter($filters, 'status') ?? 'perlu_tindakan';
        $search = trim($this->stringFilter($filters, 'search') ?? '');
        $tab = $this->stringFilter($filters, 'tab') ?? 'pendaftaran';
        $employeeRows = $this->employeeQuery->employeeRows((int) $periode, $status, $search);
        $statusCounts = $this->employeeQuery->statusCounts((int) $periode, $search);

        $selectedEmployee = $pegawaiId === null
            ? null
            : Employee::query()->select(['id', 'nama_lengkap', 'nip'])->find($pegawaiId);
        $selectedBalance = $selectedEmployee === null
            ? null
            : $this->rekapQuery->balanceRows($filters)->first();
        $ledgerBase = LeaveBalanceLedger::query()
            ->select(['id', 'employee_id', 'tahun', 'event_type', 'amount', 'source_year', 'reason', 'occurred_at', 'created_at'])
            ->where('tahun', (int) $periode)
            ->when(
                $selectedEmployee !== null,
                fn ($query) => $query->where('employee_id', $selectedEmployee->id),
                fn ($query) => $query->whereRaw('1 = 0'),
            );
        $ledgerRows = (clone $ledgerBase)
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'page_ledger')
            ->withQueryString();
        $rolloverRows = (clone $ledgerBase)
            ->whereIn('event_type', [
                LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
                LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED,
                LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED,
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return compact(
            'periode',
            'pegawaiId',
            'status',
            'search',
            'tab',
            'employeeRows',
            'statusCounts',
            'selectedEmployee',
            'selectedBalance',
            'ledgerRows',
            'rolloverRows',
        );
    }

    /** @param array<string, mixed> $filters */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
