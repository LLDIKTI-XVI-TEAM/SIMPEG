<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Queries\Cuti\CutiRekapQuery;
use App\Services\Rbac\UiPermissionCapabilityService;

class ShowCutiRekapAction
{
    public function __construct(
        private readonly CutiRekapQuery $rekapQuery,
        private readonly UiPermissionCapabilityService $capabilities,
    ) {}

    /**
     * Menyusun seluruh view model rekap dari query kanonis dan sumber yang tetap terbatas.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters, ?User $actor = null): array
    {
        $periode = $this->stringFilter($filters, 'periode');
        $unit = $this->stringFilter($filters, 'unit');
        $pegawaiId = $this->stringFilter($filters, 'pegawai');
        $jenisId = $this->stringFilter($filters, 'jenis');
        $balanceQuery = $this->rekapQuery->balanceRows($filters);
        $summary = [
            ['label' => 'Total Pegawai', 'value' => Employee::query()->whereActiveStatus()->count(), 'caption' => 'Pegawai aktif', 'tone' => 'primary'],
            ['label' => 'Cuti Terpakai', 'value' => (clone $balanceQuery)->sum('terpakai'), 'caption' => 'Hari kerja tahun ini', 'tone' => 'info'],
            ['label' => 'Sisa Saldo', 'value' => (clone $balanceQuery)->sum('sisa'), 'caption' => 'Akumulasi hari', 'tone' => 'success'],
            ['label' => 'Saldo Kritis', 'value' => (clone $balanceQuery)->where('sisa', '<=', 3)->count(), 'caption' => 'Sisa <= 3 hari', 'tone' => 'danger'],
        ];

        $perPage = isset($filters['per_page']) && is_numeric($filters['per_page']) ? (int) $filters['per_page'] : 10;
        $leaveBalances = (clone $balanceQuery)->paginate($perPage, ['*'], 'page_saldo')->withQueryString();
        $leaveBalances->through(fn (LeaveBalance $balance): array => $this->mapBalance($balance));

        $usageRows = $this->rekapQuery->paginateDetailRows($filters, $perPage, 'page_usage');

        $selectedEmployee = $pegawaiId === null
            ? null
            : Employee::query()->select(['id', 'nama_lengkap', 'nip'])->find($pegawaiId);
        $unitOptions = $this->rekapQuery->unitOptions($unit);
        $jenisOptions = $this->rekapQuery->leaveTypeOptions($jenisId);
        $canAdministerBalance = $this->capabilities->allows($actor, 'cuti.balance.reconcile')
            || $this->capabilities->allows($actor, 'cuti.manual.manage');

        return compact(
            'summary', 'leaveBalances', 'usageRows',
            'periode', 'unit', 'pegawaiId', 'jenisId',
            'selectedEmployee', 'unitOptions', 'jenisOptions', 'canAdministerBalance',
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
            'unit' => $balance->getAttribute('unit_name') ?? '-',
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
