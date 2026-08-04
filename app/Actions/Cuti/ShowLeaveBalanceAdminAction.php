<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalanceLedger;
use App\Queries\Cuti\CutiRekapQuery;
use App\Queries\Cuti\LeaveBalanceAdminEmployeeQuery;
use Illuminate\Support\Carbon;

class ShowLeaveBalanceAdminAction
{
    public function __construct(
        private readonly CutiRekapQuery $rekapQuery,
        private readonly LeaveBalanceAdminEmployeeQuery $employeeQuery,
        private readonly PreviewLeaveBalanceAction $preview,
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
        $rule5Active = $selectedEmployee === null
            ? false
            : $this->preview->execute($selectedEmployee, Carbon::create((int) $periode, 1, 1)->startOfDay())['rule_5_active'];
        $balanceInitialization = $this->balanceInitialization(
            $selectedEmployee?->id,
            (int) $periode,
        );
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
            'balanceInitialization',
            'ledgerRows',
            'rolloverRows',
        );
    }

    /**
     * Menyajikan baseline immutable terpisah dari summary berjalan agar UI tidak menganggap koreksi sebagai pembukaan baru.
     *
     * @return array{initialized:bool, source:?string, baseline:?array{n2:?int,n1:?int,current:?int}, actor_id:?string, occurred_at:mixed, reason:?string}
     */
    private function balanceInitialization(?string $employeeId, int $periode): array
    {
        $empty = [
            'initialized' => false,
            'source' => null,
            'baseline' => null,
            'actor_id' => null,
            'occurred_at' => null,
            'reason' => null,
        ];

        if ($employeeId === null) {
            return $empty;
        }

        $events = LeaveBalanceLedger::query()
            ->where('employee_id', $employeeId)
            ->where('tahun', $periode)
            ->whereIn('event_type', [
                LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET,
                LeaveBalanceLedger::EVENT_ANNUAL_ENTITLEMENT_GRANTED,
                LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
                LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED,
            ])
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->get(['event_type', 'amount', 'source_year', 'reason', 'metadata', 'created_by', 'occurred_at']);
        $opening = $events->firstWhere('event_type', LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET);

        if ($opening instanceof LeaveBalanceLedger) {
            $buckets = $opening->metadata['buckets'] ?? [];

            return [
                'initialized' => true,
                'source' => 'admin',
                'baseline' => [
                    'n2' => (int) ($buckets['n2'] ?? 0),
                    'n1' => (int) ($buckets['n1'] ?? 0),
                    'current' => (int) ($buckets['current'] ?? 0),
                ],
                'actor_id' => $opening->created_by,
                'occurred_at' => $opening->occurred_at,
                'reason' => $opening->reason,
            ];
        }

        $systemEvent = $events->first(fn (LeaveBalanceLedger $event): bool => in_array($event->event_type, [
            LeaveBalanceLedger::EVENT_ANNUAL_ENTITLEMENT_GRANTED,
            LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
        ], true));

        if (! $systemEvent instanceof LeaveBalanceLedger) {
            return $empty;
        }

        $entitlement = $events->firstWhere('event_type', LeaveBalanceLedger::EVENT_ANNUAL_ENTITLEMENT_GRANTED);
        $carryOver = $events->firstWhere('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED);
        $carryMetadata = $carryOver?->metadata ?? [];

        return [
            'initialized' => true,
            'source' => 'system',
            'baseline' => [
                // Baseline sistem hanya berasal dari event immutable; summary berjalan tidak boleh menjadi fallback.
                'n2' => array_key_exists('n2', $carryMetadata) ? (int) $carryMetadata['n2'] : null,
                'n1' => array_key_exists('n1', $carryMetadata) ? (int) $carryMetadata['n1'] : null,
                'current' => $entitlement instanceof LeaveBalanceLedger ? (int) $entitlement->amount : null,
            ],
            'actor_id' => null,
            'occurred_at' => $systemEvent->occurred_at,
            'reason' => $systemEvent->reason,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
