<?php

namespace App\Support\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveUsageRecord;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Menutup gap fakta pengajuan legacy yang sudah final tanpa mengulang approval,
 * menerbitkan bukti, mengirim notifikasi, atau melakukan I/O di luar database.
 */
final class BackfillLegacyApprovedLeaveUsage
{
    private const BATCH_SIZE = 100;

    private const INVALID_REPORT_LIMIT = 50;

    private const REASON = 'Backfill fakta pengajuan cuti final-approved saat upgrade database.';

    private const REPLAY_TABLE = 'simpeg_backfill_annual_replay';

    private const WITA_TIMEZONE = 'Asia/Makassar';

    private const GATE_MAX_ATTEMPTS = 80;

    private const GATE_RETRY_DELAY_MICROSECONDS = 25_000;

    public function __construct(
        private readonly LeaveBalanceRecalculationService $recalculation,
    ) {}

    /**
     * Preflight membaca secara keyset dan wajib selesai bersih sebelum satu fakta pun ditulis.
     * Seluruh pass tetap berada dalam satu transaksi agar data invalid tidak meninggalkan batch parsial.
     *
     * @return array{facts:int,employees_recalculated:int}
     */
    public function execute(): array
    {
        $lastGateException = null;

        for ($attempt = 1; $attempt <= self::GATE_MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(fn (): array => $this->executeLocked(), 1);
            } catch (QueryException $exception) {
                if (! $this->isGateUnavailable($exception)) {
                    throw $exception;
                }

                $lastGateException = $exception;

                if ($attempt < self::GATE_MAX_ATTEMPTS) {
                    // Jeda pendek mencegah busy-loop; total retry tetap dibatasi dan seluruh lock attempt sudah rollback.
                    usleep(self::GATE_RETRY_DELAY_MICROSECONDS);
                }
            }
        }

        throw new RuntimeException(
            'Backfill approved legacy gagal memperoleh seluruh gate maintenance setelah '
            .self::GATE_MAX_ATTEMPTS.' percobaan. Hentikan writer lalu jalankan migration kembali.',
            previous: $lastGateException,
        );
    }

    /**
     * Satu percobaan cutover utuh. Kegagalan satu gate atau preflight me-rollback seluruh state percobaan.
     *
     * @return array{facts:int,employees_recalculated:int}
     */
    private function executeLocked(): array
    {
        $this->lockCutoverTables();
        $this->createReplayTable();
        $this->assertAllCandidatesValid();

        $cursor = null;
        $facts = 0;

        while (true) {
            $batch = $this->candidateBatch($cursor);

            if ($batch->isEmpty()) {
                break;
            }

            $contexts = $this->historicalActorContexts($batch);

            foreach ($batch as $candidate) {
                $context = $contexts[(string) $candidate->request_id] ?? [
                    'actor' => null,
                    'evidence' => 'historical_actor_unresolved',
                ];
                $this->assertAnnualHorizonBeforeWrite($candidate);
                $record = $this->writeFact($candidate, $context['actor'], $context['evidence']);
                $facts++;

                if ($candidate->leave_type_code === 'tahunan') {
                    $year = (int) substr((string) $candidate->start_date, 0, 4);
                    $this->stageAnnualReplay((string) $candidate->employee_id, $year);
                }

                $cursor = (string) $candidate->request_id;
            }
        }

        $employeesRecalculated = $this->replayAnnualEmployees();

        return [
            'facts' => $facts,
            'employees_recalculated' => $employeesRecalculated,
        ];
    }

    /**
     * Semua gate memakai NOWAIT. Jika gate kedua dan seterusnya belum tersedia, transaction attempt
     * di-rollback sehingga cutover tidak pernah menunggu gate lain sambil memegang gate pertama.
     */
    private function lockCutoverTables(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Backfill approved legacy hanya didukung pada PostgreSQL.');
        }

        DB::statement('LOCK TABLE leave_requests IN EXCLUSIVE MODE NOWAIT');
        DB::statement('LOCK TABLE employees IN EXCLUSIVE MODE NOWAIT');
        DB::statement('LOCK TABLE leave_usage_records IN SHARE ROW EXCLUSIVE MODE NOWAIT');
        DB::statement('LOCK TABLE leave_balance_ledger IN SHARE ROW EXCLUSIVE MODE NOWAIT');
    }

    private function isGateUnavailable(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '55P03';
    }

    /** PostgreSQL menampung agregasi employee agar memory PHP tidak tumbuh mengikuti volume legacy. */
    private function createReplayTable(): void
    {
        DB::statement(sprintf(<<<'SQL'
CREATE TEMPORARY TABLE %s (
    employee_id uuid PRIMARY KEY,
    earliest_year integer NOT NULL CHECK (earliest_year BETWEEN 1900 AND 2100)
) ON COMMIT DROP
SQL, self::REPLAY_TABLE));
    }

    private function stageAnnualReplay(string $employeeId, int $year): void
    {
        DB::statement(sprintf(<<<'SQL'
INSERT INTO %s (employee_id, earliest_year)
VALUES (?::uuid, ?)
ON CONFLICT (employee_id) DO UPDATE
SET earliest_year = LEAST(%s.earliest_year, EXCLUDED.earliest_year)
SQL, self::REPLAY_TABLE, self::REPLAY_TABLE), [$employeeId, $year]);
    }

    /** Replay temporary table tetap keyset-bounded dan maksimal sekali per employee. */
    private function replayAnnualEmployees(): int
    {
        $cursor = null;
        $recalculated = 0;

        while (true) {
            $batch = DB::table(self::REPLAY_TABLE)
                ->when($cursor !== null, fn ($query) => $query->where('employee_id', '>', $cursor))
                ->orderBy('employee_id')
                ->limit(self::BATCH_SIZE)
                ->get(['employee_id', 'earliest_year']);

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $replay) {
                $employeeId = (string) $replay->employee_id;
                // Jalur upgrade membaca row legacy tanpa bergantung pada keberadaan trait atau kolom soft delete.
                $employee = Employee::query()
                    ->withoutGlobalScope(SoftDeletingScope::class)
                    ->whereKey($employeeId)
                    ->firstOrFail();
                $this->recalculation->recalculateForDatabaseUpgrade(
                    $employee,
                    (int) $replay->earliest_year,
                    self::REASON,
                );
                $cursor = $employeeId;
                $recalculated++;
            }
        }

        return $recalculated;
    }

    private function assertAllCandidatesValid(): void
    {
        $cursor = null;
        $invalidCount = 0;
        $invalidReport = [];

        while (true) {
            $batch = $this->candidateBatch($cursor);

            if ($batch->isEmpty()) {
                break;
            }

            $finalSteps = $this->finalStepsByRequest($batch);

            foreach ($batch as $candidate) {
                $errors = $this->candidateErrors(
                    $candidate,
                    $finalSteps->get((string) $candidate->request_id, collect()),
                );

                if ($errors !== []) {
                    $invalidCount++;

                    if (count($invalidReport) < self::INVALID_REPORT_LIMIT) {
                        $invalidReport[] = $candidate->request_id.': '.implode('; ', $errors);
                    }
                }

                $cursor = (string) $candidate->request_id;
            }
        }

        if ($invalidCount === 0) {
            return;
        }

        $remaining = $invalidCount - count($invalidReport);
        $suffix = $remaining > 0 ? "; dan {$remaining} request invalid lain" : '';

        throw new RuntimeException(
            'Backfill fakta approved legacy dihentikan sebelum penulisan karena data invalid: '
            .implode(' | ', $invalidReport)
            .$suffix.'. Perbaiki data sumber lalu jalankan migration kembali.',
        );
    }

    /**
     * @param  Collection<int, stdClass>  $finalSteps
     * @return list<string>
     */
    private function candidateErrors(stdClass $candidate, Collection $finalSteps): array
    {
        $errors = [];

        if ($candidate->employee_join_id === null) {
            $errors[] = 'pegawai sumber tidak ditemukan';
        }

        if ($candidate->leave_type_id === null || $candidate->leave_type_join_id === null) {
            $errors[] = 'jenis cuti sumber tidak ditemukan';
        }

        $startDate = $this->parseDate($candidate->start_date);
        $endDate = $this->parseDate($candidate->end_date);

        if ($startDate === null) {
            $errors[] = 'tanggal mulai sumber tidak tersedia atau tidak valid';
        }

        if ($endDate === null) {
            $errors[] = 'tanggal selesai sumber tidak tersedia atau tidak valid';
        }

        if ($startDate !== null && $endDate !== null) {
            if ($endDate->lt($startDate)) {
                $errors[] = 'tanggal selesai mendahului tanggal mulai';
            }

            if ($startDate->year !== $endDate->year) {
                $errors[] = 'periode melintasi tahun kalender';
            }

            if ($candidate->leave_type_code === 'tahunan' && $startDate->year > $this->currentWitaYear()) {
                $errors[] = 'tahun pemakaian Cuti Tahunan berada setelah tahun WITA berjalan';
            }
        }

        if ((int) $candidate->workdays <= 0) {
            $errors[] = 'jumlah hari kerja wajib lebih dari nol';
        }

        if ($finalSteps->count() > 1) {
            $errors[] = 'snapshot memiliki lebih dari satu step final';
        } elseif ($finalSteps->count() === 1) {
            $finalStep = $finalSteps->first();

            if ($finalStep->status !== 'approved' || $finalStep->acted_at === null) {
                $errors[] = 'snapshot step final tidak membuktikan persetujuan final';
            }
        }

        return $errors;
    }

    /** Guard terakhir menjaga horizon tahunan tetap fail-closed meski alur preflight kelak berubah. */
    private function assertAnnualHorizonBeforeWrite(stdClass $candidate): void
    {
        if ($candidate->leave_type_code !== 'tahunan') {
            return;
        }

        $usageYear = (int) substr((string) $candidate->start_date, 0, 4);

        if ($usageYear > $this->currentWitaYear()) {
            throw new RuntimeException(
                "Backfill fakta approved legacy dihentikan sebelum penulisan karena request {$candidate->request_id} "
                .'memiliki tahun pemakaian Cuti Tahunan setelah tahun WITA berjalan.',
            );
        }
    }

    private function currentWitaYear(): int
    {
        return CarbonImmutable::now(self::WITA_TIMEZONE)->year;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return Collection<int, stdClass> */
    private function candidateBatch(?string $cursor): Collection
    {
        return DB::table('leave_requests as requests')
            ->leftJoin('employees as employees', 'employees.id', '=', 'requests.employee_id')
            ->leftJoin('ref_jenis_cuti as leave_types', 'leave_types.id', '=', 'requests.jenis_cuti_id')
            ->where('requests.status', 'disetujui')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('leave_usage_records as existing_usage')
                    ->whereColumn('existing_usage.leave_request_id', 'requests.id');
            })
            ->when($cursor !== null, fn ($query) => $query->where('requests.id', '>', $cursor))
            ->orderBy('requests.id')
            ->limit(self::BATCH_SIZE)
            ->get([
                'requests.id as request_id',
                'requests.employee_id',
                'employees.id as employee_join_id',
                'requests.jenis_cuti_id as leave_type_id',
                'leave_types.id as leave_type_join_id',
                'leave_types.code as leave_type_code',
                'requests.leave_request_case_id',
                'requests.tanggal_mulai as start_date',
                'requests.tanggal_selesai as end_date',
                'requests.jumlah_hari_kerja as workdays',
                'requests.status',
            ]);
    }

    /**
     * @param  Collection<int, stdClass>  $batch
     * @return Collection<int|string, Collection<int, stdClass>>
     */
    private function finalStepsByRequest(Collection $batch): Collection
    {
        return DB::table('leave_request_steps')
            ->whereIn('leave_request_id', $batch->pluck('request_id'))
            ->where('is_final', true)
            ->orderBy('leave_request_id')
            ->orderBy('step_order')
            ->get(['leave_request_id', 'approver_employee_id', 'status', 'acted_at'])
            ->groupBy('leave_request_id');
    }

    /**
     * Identitas User hanya diisi bila snapshot step final menunjuk Employee yang saat ini
     * memiliki mapping User. Tanpa bukti final itu, FK aktor dibiarkan null.
     *
     * @param  Collection<int, stdClass>  $batch
     * @return array<string, array{actor:?User,evidence:string}>
     */
    private function historicalActorContexts(Collection $batch): array
    {
        $stepsByRequest = $this->finalStepsByRequest($batch);
        $approverEmployeeIds = $stepsByRequest
            ->flatten(1)
            ->pluck('approver_employee_id')
            ->filter()
            ->unique()
            ->values();
        $usersByEmployee = User::query()
            ->whereIn('employee_id', $approverEmployeeIds)
            ->get()
            ->keyBy('employee_id');
        $contexts = [];

        foreach ($batch as $candidate) {
            $requestId = (string) $candidate->request_id;
            $finalStep = $stepsByRequest->get($requestId)?->sole();
            $actor = $finalStep === null
                ? null
                : $usersByEmployee->get($finalStep->approver_employee_id);
            $evidence = $actor instanceof User
                ? 'final_step_user_mapping'
                : 'historical_actor_unresolved';

            $contexts[$requestId] = [
                'actor' => $actor instanceof User ? $actor : null,
                'evidence' => $evidence,
            ];
        }

        return $contexts;
    }

    private function writeFact(stdClass $candidate, ?User $actor, string $actorEvidence): LeaveUsageRecord
    {
        $record = LeaveUsageRecord::query()->create([
            'employee_id' => $candidate->employee_id,
            'leave_type_id' => $candidate->leave_type_id,
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'reconciliation_set_id' => null,
            'leave_request_id' => $candidate->request_id,
            'leave_request_case_id' => $candidate->leave_request_case_id,
            'usage_year' => (int) substr((string) $candidate->start_date, 0, 4),
            'effective_date' => $candidate->start_date,
            'start_date' => $candidate->start_date,
            'end_date' => $candidate->end_date,
            'workdays' => $candidate->workdays,
            'administrative_note' => self::REASON,
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor?->id,
        ]);
        $snapshot = [
            'id' => $record->id,
            'employee_id' => $record->employee_id,
            'leave_type_id' => $record->leave_type_id,
            'source_type' => $record->source_type,
            'leave_request_id' => $record->leave_request_id,
            'leave_request_case_id' => $record->leave_request_case_id,
            'usage_year' => $record->usage_year,
            'effective_date' => $record->effective_date->toDateString(),
            'start_date' => $record->start_date?->toDateString(),
            'end_date' => $record->end_date?->toDateString(),
            'workdays' => $record->workdays,
            'record_status' => $record->record_status,
            'recorded_by' => $record->recorded_by,
        ];
        $provenance = [
            'operation' => 'approved_usage_backfilled',
            'provenance' => 'database_upgrade',
            'historical_actor_evidence' => $actorEvidence,
            'request_status' => $candidate->status,
            'reason' => self::REASON,
        ];

        // Retry sah sudah dikeluarkan oleh kandidat existing fact; dedup tanpa fact adalah collision yang wajib gagal.
        LeaveBalanceLedger::query()->create([
            'employee_id' => $record->employee_id,
            'leave_request_id' => $record->leave_request_id,
            'tahun' => $record->usage_year,
            'event_type' => LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED,
            'amount' => $record->workdays,
            'source_year' => $record->usage_year,
            'reason' => self::REASON,
            'dedup_key' => "usage:backfill:approved_request:{$candidate->request_id}",
            'metadata' => array_merge([
                'usage_record_id' => $record->id,
                'source_type' => $record->source_type,
                'record_status' => $record->record_status,
            ], $provenance),
            'created_by' => $actor?->id,
            'occurred_at' => now(),
        ]);

        if ($actor !== null) {
            AuditService::logAsOrFail(
                $actor->id,
                (string) $actor->name,
                'CREATE',
                'LeaveUsageRecord',
                $record->id,
                null,
                array_merge($snapshot, $provenance, ['actor_role' => $actor->role]),
            );
        } else {
            AuditService::logDatabaseUpgradeOrFail(
                'CREATE',
                'LeaveUsageRecord',
                $record->id,
                null,
                array_merge($snapshot, $provenance),
            );
        }

        return $record;
    }
}
