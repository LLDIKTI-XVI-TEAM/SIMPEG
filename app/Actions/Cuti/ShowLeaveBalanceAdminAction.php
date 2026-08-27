<?php

namespace App\Actions\Cuti;

use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveUsageExternalApprovalStep;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\User;
use App\Queries\Cuti\CurrentApprovalChainPreviewQuery;
use App\Queries\Cuti\LeaveBalanceAdminEmployeeQuery;
use App\Queries\Cuti\LeaveUsageAdminQuery;
use App\Queries\Cuti\ManualLeaveCaseOptionQuery;
use App\Services\Cuti\AnnualLeaveBusinessClock;
use App\Services\Cuti\LeaveBalanceService;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

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
    public function execute(array $filters): array
    {
        // Tahun administrasi mengikuti kalender bisnis WITA, bukan timezone proses atau parameter klien.
        $periode = (string) $this->businessClock->currentYear();
        $filters['periode'] = $periode;
        $pegawaiId = $this->stringFilter($filters, 'pegawai');
        $status = $this->stringFilter($filters, 'status') ?? 'perlu_tindakan';
        $search = trim($this->stringFilter($filters, 'search') ?? '');
        $tab = $this->stringFilter($filters, 'tab') ?? 'pendaftaran';
        $employeeRows = $this->employeeQuery->employeeRows((int) $periode, $status, $search);
        $statusCounts = $this->employeeQuery->statusCounts((int) $periode, $search);

        $workspace = $pegawaiId === null
            ? [
                'employee' => null,
                'balance' => null,
                'rule5Active' => false,
                'activeReserved' => 0,
                'dutyProtected' => 0,
            ]
            : $this->employeeQuery->selectedWorkspace($pegawaiId, (int) $periode);
        $selectedEmployee = $workspace['employee'];
        $selectedBalance = $workspace['balance'];
        $rule5Active = $workspace['rule5Active'];
        $balanceSummary = $this->balanceSummary(
            $selectedBalance,
            $rule5Active,
            $workspace['activeReserved'],
            $workspace['dutyProtected'],
        );
        $balanceReconciliation = $this->balanceReconciliation(
            $selectedEmployee?->id,
            (int) $periode,
            $tab === 'riwayat',
        );
        $usageFilters = $this->usageFilters($filters);
        $leaveTypeOptions = $this->usageQuery->leaveTypeOptions($this->stringFilter($filters, 'leave_type'));
        $editUsageId = $this->stringFilter($filters, 'edit_usage');
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
        // Error validasi selalu menang; koreksi mengambil snapshot persisted, sedangkan create sengaja mulai kosong.
        $initialApprovalSteps = session()->hasOldInput('approval_steps')
            ? old('approval_steps')
            : ($editableApprovalSteps ?? []);
        [$canReconcile, $canManageManual] = $this->uiCapabilities();
        // Preview dan opsi hanya diperlukan saat panel manual dibuka agar tab administrasi lain tidak memuat query tambahan.
        $manualWorkspaceActive = $canManageManual
            && $selectedEmployee !== null
            && ($tab === 'manual' || $editableUsage !== null);
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
            'balanceReconciliation',
            'usageFilters',
            'leaveTypeOptions',
            'usageRows',
            'usageRowsUseScalarType',
            'editUsageId',
            'editableUsage',
            'manualAction',
            'editableApprovalSteps',
            'initialApprovalSteps',
            'canReconcile',
            'canManageManual',
            'manualWorkspaceActive',
            'currentApprovalChainPreview',
            'manualLeaveCaseOptions',
            'ledgerRows',
            'rolloverRows',
        );
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
     * UI memakai satu pembacaan permission; route, FormRequest, dan Action mutation tetap gate otoritatif.
     *
     * @return array{bool,bool}
     */
    private function uiCapabilities(): array
    {
        $actor = auth()->user();
        $effectiveRole = $actor instanceof User ? $actor->getEffectiveRole() : null;

        if (! $actor instanceof User || $effectiveRole !== 'admin_kepegawaian') {
            return [false, false];
        }

        $permissions = DB::table('roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.name', $effectiveRole)
            ->whereIn('permissions.name', ['cuti.balance.reconcile', 'cuti.manual.manage'])
            ->pluck('permissions.name');

        return [
            $permissions->contains('cuti.balance.reconcile'),
            $permissions->contains('cuti.manual.manage'),
        ];
    }

    /**
     * Menyajikan set aktif dan tiga fakta pemakaian exact-year tanpa fallback ke projection atau ledger legacy.
     *
     * @return array{reconciled:bool, current_year_reconciled:bool, balance_year:?int, set_id:?string, usage:?array{n2:int,n1:int,current:int}, actor_id:?string, reconciled_at:mixed, note:?string, history:list<array<string,mixed>>|Paginator}
     */
    private function balanceReconciliation(?string $employeeId, int $periode, bool $includeHistory): array
    {
        $empty = [
            'reconciled' => false,
            'current_year_reconciled' => false,
            'balance_year' => null,
            'set_id' => null,
            'usage' => null,
            'actor_id' => null,
            'reconciled_at' => null,
            'note' => null,
            'history' => [],
        ];

        if ($employeeId === null) {
            return $empty;
        }

        // Tiga inner join exact-year membuat snapshot tidak lengkap gagal tertutup dalam satu query.
        $set = LeaveUsageReconciliationSet::query()
            ->from('leave_usage_reconciliation_sets as reconciliation_sets')
            ->join('leave_usage_records as usage_n2', function (JoinClause $join): void {
                $this->joinActiveAnnualUsage($join, 'usage_n2', 'reconciliation_sets.balance_year - 2');
            })
            ->join('leave_usage_records as usage_n1', function (JoinClause $join): void {
                $this->joinActiveAnnualUsage($join, 'usage_n1', 'reconciliation_sets.balance_year - 1');
            })
            ->join('leave_usage_records as usage_current', function (JoinClause $join): void {
                $this->joinActiveAnnualUsage($join, 'usage_current', 'reconciliation_sets.balance_year');
            })
            ->select([
                'reconciliation_sets.id',
                'reconciliation_sets.balance_year',
                'reconciliation_sets.recorded_by',
                'reconciliation_sets.reconciled_at',
                'reconciliation_sets.administrative_note',
                'usage_n2.workdays as usage_n2',
                'usage_n1.workdays as usage_n1',
                'usage_current.workdays as usage_current',
            ])
            ->where('reconciliation_sets.employee_id', $employeeId)
            ->where('reconciliation_sets.status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
            ->first();

        if (! $set instanceof LeaveUsageReconciliationSet) {
            return $empty;
        }

        $history = [];
        if ($includeHistory) {
            // Riwayat lintas tahun tetap bounded dan dapat dinavigasi. Hanya metadata
            // aman yang diteruskan; path, disk, dan nama internal file tidak dibawa ke view.
            $history = LeaveUsageReconciliationSet::query()
                ->select(['id', 'balance_year', 'status', 'reconciled_at'])
                ->with(['documents:id,leave_usage_reconciliation_set_id,original_name,mime_type,size_bytes,created_at'])
                ->where('employee_id', $employeeId)
                ->whereIn('status', [LeaveUsageReconciliationSet::STATUS_ACTIVE, LeaveUsageReconciliationSet::STATUS_SUPERSEDED])
                ->orderByDesc('reconciled_at')
                ->orderByDesc('id')
                ->simplePaginate(20, ['*'], 'page_reconciliation_history')
                ->withQueryString();
            $history->setCollection($history->getCollection()->map(fn (LeaveUsageReconciliationSet $item): array => [
                'id' => $item->id,
                'balance_year' => (int) $item->balance_year,
                'status' => $item->status,
                'reconciled_at' => $item->reconciled_at,
                'documents' => $item->documents->map(fn ($document): array => [
                    'id' => $document->id,
                    'original_name' => $document->original_name,
                    'mime_type' => $document->mime_type,
                    'size_bytes' => $document->size_bytes,
                ])->all(),
            ]));
        }

        return [
            'reconciled' => true,
            'current_year_reconciled' => (int) $set->balance_year === $periode,
            'balance_year' => (int) $set->balance_year,
            'set_id' => $set->id,
            'usage' => [
                'n2' => (int) $set->getAttribute('usage_n2'),
                'n1' => (int) $set->getAttribute('usage_n1'),
                'current' => (int) $set->getAttribute('usage_current'),
            ],
            'actor_id' => $set->recorded_by,
            'reconciled_at' => $set->reconciled_at,
            'note' => $set->administrative_note,
            'history' => $history,
        ];
    }

    /** Mengikat satu fakta rekonsiliasi aktif ke set dan tahun yang tepat. */
    private function joinActiveAnnualUsage(JoinClause $join, string $alias, string $yearExpression): void
    {
        $join->on("{$alias}.reconciliation_set_id", '=', 'reconciliation_sets.id')
            ->where("{$alias}.source_type", LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION)
            ->where("{$alias}.record_status", LeaveUsageRecord::STATUS_ACTIVE)
            ->whereColumn("{$alias}.usage_year", DB::raw($yearExpression));
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
