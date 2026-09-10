<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveUsageExternalApprovalStep;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Queries\Cuti\CurrentApprovalChainPreviewQuery;
use App\Queries\Cuti\LeaveBalanceAdminEmployeeQuery;
use App\Queries\Cuti\LeaveUsageAdminQuery;
use App\Queries\Cuti\ManualLeaveCaseOptionQuery;
use App\Services\Cuti\AnnualLeaveBusinessClock;
use App\Services\Cuti\LeaveBalanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class ShowLeaveBalanceAdminAction
{
    public function __construct(
        private readonly LeaveBalanceAdminEmployeeQuery $employeeQuery,
        private readonly LeaveUsageAdminQuery $usageQuery,
        private readonly LeaveBalanceService $balances,
        private readonly CurrentApprovalChainPreviewQuery $approvalChainPreviewQuery,
        private readonly ManualLeaveCaseOptionQuery $manualLeaveCaseOptionQuery,
        private readonly AnnualLeaveBusinessClock $businessClock,
    ) {}

    /**
     * Menyusun data administrasi saldo dari sumber rekap kanonis agar saldo dan ledger tetap konsisten.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters, User $actor): array
    {
        // Guard membaca matrix terbaru; cache capability UI tidak boleh meloloskan revoke dalam request yang sama.
        // Saldo pegawai lain adalah capability RBAC. Mutasi manual/reconcile tetap
        // memerlukan permission masing-masing pada Action penulisnya.
        $canManageManual = $actor->hasPermission('cuti.manual.manage');
        if (! $actor->hasPermission('cuti.balance.read')) {
            throw new AuthorizationException('Anda tidak memiliki izin membaca administrasi pemakaian cuti.');
        }

        // Tahun administrasi mengikuti kalender bisnis WITA, bukan timezone proses atau parameter klien.
        $periode = (string) $this->businessClock->currentYear();
        $filters['periode'] = $periode;
        $pegawaiId = $this->stringFilter($filters, 'pegawai');
        $status = $this->stringFilter($filters, 'status') ?? 'perlu_tindakan';
        $search = trim($this->stringFilter($filters, 'search') ?? '');
        $tab = $this->stringFilter($filters, 'tab') ?? 'pendaftaran';
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 10;
        $employeeRows = $this->employeeQuery->employeeRows((int) $periode, $status, $search, $actor, $perPage);
        $statusCounts = $this->employeeQuery->statusCounts((int) $periode, $search, $actor);

        $workspace = $pegawaiId === null
            ? [
                'employee' => null,
                'balance' => null,
                'rule5Active' => false,
                'activeReserved' => 0,
                'dutyProtected' => 0,
            ]
            : $this->employeeQuery->selectedWorkspace($pegawaiId, (int) $periode, $actor);
        $selectedEmployee = $workspace['employee'];
        $selectedBalance = $workspace['balance'];
        $rule5Active = $workspace['rule5Active'];
        $balanceSummary = $this->balanceSummary(
            $selectedBalance,
            $rule5Active,
            $workspace['activeReserved'],
            $workspace['dutyProtected'],
        );
        $usageSummary = $this->usageSummary(
            $selectedEmployee?->id,
            (int) $periode,
        );
        $usageFilters = $this->usageFilters($filters);
        $leaveTypeOptions = $this->usageQuery->leaveTypeOptions($this->stringFilter($filters, 'leave_type'));
        $editUsageId = $this->stringFilter($filters, 'edit_usage');
        if ($editUsageId !== null) {
            // Kepemilikan diperiksa terpisah dari status/filter histori agar redirect koreksi atau pembatalan tetap dapat dibaca.
            abort_if($selectedEmployee === null || ! Str::isUuid($editUsageId), 404);
            abort_unless(LeaveUsageRecord::query()
                ->whereKey($editUsageId)
                ->where('employee_id', $selectedEmployee->id)
                ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
                ->exists(), 404);
        }
        $usageRowsUseScalarType = $tab === 'manual' && $editUsageId === null;
        // Form catat manual tetap merender jenis dan dokumen histori, tetapi tidak membaca relasi pegawai/request yang tidak dipakai.
        $usageRows = $this->usageQuery->paginate(
            $selectedEmployee?->id,
            $usageFilters,
            loadWorkspaceRelations: ! $usageRowsUseScalarType,
        );
        $editableUsage = null;

        // Editor hanya menerima fakta manual aktif yang sudah berada dalam halaman histori terotorisasi.
        foreach ($usageRows->items() as $usageRow) {
            if ($usageRow->id === $editUsageId
                && $usageRow->source_type === LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL
                && $usageRow->record_status === LeaveUsageRecord::STATUS_ACTIVE) {
                $editableUsage = $usageRow;
                break;
            }
        }
        $manualAction = $this->stringFilter($filters, 'manual_action') === 'cancel' ? 'cancel' : 'correct';
        $editableApprovalSteps = $editableUsage === null
            ? null
            : $editableUsage->externalApprovalSteps
                ->map(fn (LeaveUsageExternalApprovalStep $step): array => [
                    'step_type' => $step->step_type,
                    'approver_source' => $step->approver_source,
                    'approver_employee_id' => $step->approver_employee_id,
                    'approver_name' => $step->approver_source === 'external_official'
                        ? $step->approver_name_snapshot
                        : null,
                    'approver_label' => $step->approver_name_snapshot,
                    'approver_position' => $step->approver_source === 'external_official'
                        ? $step->approver_position_snapshot
                        : null,
                    'approver_institution' => $step->approver_source === 'external_official'
                        ? $step->approver_institution_snapshot
                        : null,
                    'acted_on' => $step->acted_on->toDateString(),
                    'decision_note' => $step->decision_note,
                ])
                ->values()
                ->all();
        // Preview dan opsi hanya diperlukan saat panel manual dibuka agar tab administrasi lain tidak memuat query tambahan.
        $manualWorkspaceActive = $canManageManual
            && $selectedEmployee !== null
            && ($tab === 'manual' || $editableUsage !== null);
        // Error validasi selalu menang; koreksi mengambil snapshot persisted, sedangkan create sengaja mulai kosong.
        $initialApprovalSteps = $manualWorkspaceActive && session()->hasOldInput('approval_steps')
            ? $this->restoreApprovalLabels(old('approval_steps'), $editableApprovalSteps ?? [])
            : ($editableApprovalSteps ?? []);
        // Koreksi memakai snapshot historis pada cutover berikutnya, bukan current chain; jangan memuat preview konfigurasi saat editor aktif.
        $currentApprovalChainPreview = $manualWorkspaceActive && $editableUsage === null
            ? $this->approvalChainPreviewQuery->forEmployee($selectedEmployee?->id)
            : ['available' => false, 'valid' => false, 'warnings' => [], 'steps' => []];
        $manualLeaveCaseOptions = $manualWorkspaceActive
            ? $this->manualLeaveCaseOptionQuery->forSelectedEmployee($selectedEmployee, $editableUsage?->leave_request_case_id)
            : collect();
        $ledgerBase = LeaveBalanceLedger::query()
            ->select(['id', 'employee_id', 'tahun', 'event_type', 'amount', 'source_year', 'reason', 'metadata', 'created_by', 'occurred_at', 'created_at'])
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
            'rule5Active',
            'balanceSummary',
            'usageSummary',
            'usageFilters',
            'leaveTypeOptions',
            'usageRows',
            'usageRowsUseScalarType',
            'editUsageId',
            'editableUsage',
            'manualAction',
            'editableApprovalSteps',
            'initialApprovalSteps',
            'canManageManual',
            'manualWorkspaceActive',
            'currentApprovalChainPreview',
            'manualLeaveCaseOptions',
            'ledgerRows',
            'rolloverRows',
        );
    }

    /**
     * Form lama hanya membawa UUID, bukan label. Pulihkan identitas minimum secara bounded;
     * label kiriman klien tidak dipercaya dan nama snapshot koreksi tidak diganti profil terbaru.
     *
     * @param  list<array<string, mixed>>  $persistedSteps
     * @return list<array<string, mixed>>
     */
    private function restoreApprovalLabels(mixed $oldSteps, array $persistedSteps): array
    {
        $steps = collect(is_array($oldSteps) ? $oldSteps : [])->take(10)
            ->filter(fn (mixed $step): bool => is_array($step))->values();
        $ids = $steps->where('approver_source', 'simpeg_employee')->pluck('approver_employee_id')
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))->unique()->values()->all();
        $labels = $ids === [] ? collect() : Employee::query()->whereIn('id', $ids)
            ->get(['id', 'nama_lengkap', 'nip'])
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => "{$employee->nama_lengkap} - NIP {$employee->nip}"]);
        $snapshots = collect($persistedSteps)->where('approver_source', 'simpeg_employee');

        return $steps->map(function (array $step) use ($labels, $snapshots): array {
            $step['approver_label'] = '';
            $id = $step['approver_employee_id'] ?? null;
            if (($step['approver_source'] ?? null) === 'simpeg_employee' && is_string($id)) {
                // Ikuti writer: satu snapshot dikonsumsi per UUID, bukan berdasarkan peran atau indeks tahap.
                $key = $snapshots->search(fn (array $snapshot): bool => $snapshot['approver_employee_id'] === $id);
                $snapshot = $key === false ? null : $snapshots->pull($key);
                $step['approver_label'] = $snapshot['approver_label'] ?? $labels->get($id, '');
            }

            return $step;
        })->all();
    }

    /**
     * Menampilkan hak pembuka dan ketersediaan efektif tanpa menjadikan UI sumber mutasi saldo.
     *
     * @return array{total_hak:int,saldo_aktual:int,dialokasikan_aktif:int,dilindungi_penangguhan_dinas:int,saldo_dapat_diajukan:int}
     */
    private function balanceSummary(
        ?LeaveBalance $balance,
        bool $rule5Active,
        int $activeReserved,
        int $dutyProtected,
    ): array {
        $buckets = $balance === null || $rule5Active
            ? ['n2' => 0, 'n1' => 0, 'current' => 0]
            : [
                'n2' => (int) $balance->sisa_n2,
                'n1' => (int) $balance->sisa_n1,
                'current' => (int) $balance->sisa_tahun_berjalan,
            ];
        $availability = $this->balances->availabilitySummary(
            $buckets,
            $rule5Active ? 0 : $activeReserved,
            $rule5Active ? 0 : $dutyProtected,
        );

        return [
            'total_hak' => $rule5Active
                ? 0
                : (int) ($balance?->jatah_awal ?? 0) + (int) ($balance?->carry_over ?? 0),
        ] + $availability;
    }

    /**
     * Menetapkan default read model tanpa mengubah query string klien yang dipertahankan paginator.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function usageFilters(array $filters): array
    {
        return [
            'source_type' => $this->stringFilter($filters, 'source_type'),
            'record_status' => $this->stringFilter($filters, 'record_status'),
            'usage_year' => $this->integerFilter($filters, 'usage_year'),
            'leave_type' => $this->stringFilter($filters, 'leave_type'),
            'sort' => $this->stringFilter($filters, 'sort') ?? 'effective_date',
            'direction' => $this->stringFilter($filters, 'direction') ?? 'desc',
            'per_page_usage' => $this->integerFilter($filters, 'per_page_usage') ?? 10,
        ];
    }

    /**
     * Ringkasan ini bersifat read-only dan hanya menjumlahkan fakta aktif yang telah tercatat.
     * Tidak ada snapshot agregat maupun fallback angka nol yang dipersistensikan.
     *
     * @return array{n2:int,n1:int,current:int}
     */
    private function usageSummary(?string $employeeId, int $periode): array
    {
        $summary = ['n2' => 0, 'n1' => 0, 'current' => 0];

        if ($employeeId === null) {
            return $summary;
        }

        $annualTypeId = RefJenisCuti::query()->where('code', 'tahunan')->value('id');
        if ($annualTypeId === null) {
            return $summary;
        }

        $totals = LeaveUsageRecord::query()
            ->selectRaw('usage_year, SUM(workdays) as total_workdays')
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $annualTypeId)
            ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->whereBetween('usage_year', [$periode - 2, $periode])
            ->groupBy('usage_year')
            ->pluck('total_workdays', 'usage_year');

        return [
            'n2' => (int) ($totals[$periode - 2] ?? 0),
            'n1' => (int) ($totals[$periode - 1] ?? 0),
            'current' => (int) ($totals[$periode] ?? 0),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $filters */
    private function integerFilter(array $filters, string $key): ?int
    {
        $value = $filters[$key] ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
