<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Queries\Cuti\CutiRekapQuery;
use App\Support\Cuti\CutiReportStatusFormatter;

class ShowCutiRekapAction
{
    public function __construct(
        private readonly CutiRekapQuery $rekapQuery,
        private readonly CutiReportStatusFormatter $statusFormatter,
    ) {}

    /**
     * Menyusun seluruh view model rekap dari query kanonis dan sumber yang tetap terbatas.
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
        $balanceQuery = $this->rekapQuery->balanceRows($filters);
        $summary = [
            ['label' => 'Total Pegawai', 'value' => Employee::query()->where('status_aktif', 'Aktif')->count(), 'caption' => 'Pegawai aktif', 'tone' => 'primary'],
            ['label' => 'Cuti Terpakai', 'value' => (clone $balanceQuery)->sum('terpakai'), 'caption' => 'Hari kerja tahun ini', 'tone' => 'info'],
            ['label' => 'Sisa Saldo', 'value' => (clone $balanceQuery)->sum('sisa'), 'caption' => 'Akumulasi hari', 'tone' => 'success'],
            ['label' => 'Saldo Kritis', 'value' => (clone $balanceQuery)->where('sisa', '<=', 3)->count(), 'caption' => 'Sisa <= 3 hari', 'tone' => 'danger'],
        ];

        $leaveBalances = (clone $balanceQuery)->paginate(10, ['*'], 'page_saldo')->withQueryString();
        $leaveBalances->through(fn (LeaveBalance $balance): array => $this->mapBalance($balance));

        $usageRows = $this->rekapQuery->detailRows($filters)
            ->paginate(10, ['*'], 'page_usage')
            ->withQueryString();
        $usageRows->through(fn (LeaveRequest $leaveRequest): array => [
            'nama' => $leaveRequest->employee?->nama_lengkap ?? '-',
            'nip' => $leaveRequest->employee?->nip ?? '-',
            'jenis' => $leaveRequest->jenisCuti?->nama ?? '-',
            'mulai' => $leaveRequest->tanggal_mulai?->format('d M Y') ?? '-',
            'selesai' => $leaveRequest->tanggal_selesai?->format('d M Y') ?? '-',
            'hari' => $leaveRequest->jumlah_hari_kerja,
            'status' => $leaveRequest->status,
            'status_label' => $this->statusFormatter->format($leaveRequest),
        ]);

        $selectedEmployee = $pegawaiId === null
            ? null
            : Employee::query()->select(['id', 'nama_lengkap', 'nip'])->find($pegawaiId);
        $selectedBalance = $selectedEmployee === null
            ? null
            : $this->rekapQuery->balanceRows($filters)->first();
        $ledgerBase = LeaveBalanceLedger::query()
            ->select(['id', 'employee_id', 'tahun', 'event_type', 'amount', 'source_year', 'reason', 'occurred_at', 'created_at'])
            ->when(
                $selectedEmployee !== null,
                fn ($query) => $query->where('employee_id', $selectedEmployee->id),
                fn ($query) => $query->whereRaw('1 = 0'),
            );
        $ledgerRows = (clone $ledgerBase)
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->paginate(10, ['*'], 'page_ledger')
            ->withQueryString();
        $rolloverRows = (clone $ledgerBase)
            ->whereIn('event_type', [
                LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
                LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED,
                LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED,
            ])
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->get();

        return compact(
            'summary', 'leaveBalances', 'usageRows',
            'periode', 'unit', 'pegawaiId', 'jenisId',
            'selectedEmployee', 'selectedBalance', 'ledgerRows', 'rolloverRows',
        );
    }

    /** @return array<string, int|string> */
    private function mapBalance(LeaveBalance $balance): array
    {
        $status = $balance->sisa <= 3 ? 'Kritis' : ($balance->sisa <= 6 ? 'Perhatian' : 'Aman');

        return [
            'employee_id' => $balance->employee_id,
            'tahun' => $balance->tahun,
            'nama' => $balance->employee?->nama_lengkap ?? '-',
            'nip' => $balance->employee?->nip ?? '-',
            'unit' => $balance->employee?->jabatan_terakhir ?? '-',
            'jatah' => $balance->jatah_awal,
            'carry' => $balance->carry_over,
            'sisa_n2' => $balance->sisa_n2,
            'sisa_n1' => $balance->sisa_n1,
            'sisa_tahun_berjalan' => $balance->sisa_tahun_berjalan,
            'hangus' => $balance->hangus,
            'terpakai' => $balance->terpakai,
            'sisa' => $balance->sisa,
            'status' => $status,
            'status_label' => $status,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
