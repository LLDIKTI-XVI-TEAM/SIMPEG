<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const ACTIVE_STATUSES = [
        'menunggu_approval',
        'ditangguhkan',
        'perlu_perubahan',
    ];

    private const BATCH_SIZE = 200;

    private const DEDUP_NAMESPACE_REGEX = '^leave_reservation:[^:]*:released:nonannual_upgrade:.*$';

    private const GATE_MAX_ATTEMPTS = 80;

    private const GATE_RETRY_DELAY_MICROSECONDS = 25_000;

    private const REASON = 'Reservasi aktif non-tahunan dinetralkan saat upgrade invariant Cuti Tahunan.';

    private const RELEASE_CONTEXT = 'nonannual_active_reservation_upgrade';

    private const SYSTEM_ACTOR = 'SIMPEG Database Upgrade';

    private const SYSTEM_SOURCE = 'database_upgrade';

    /** @var list<string> */
    private array $requiredTables = [
        'ref_jenis_cuti',
        'leave_requests',
        'leave_balance_reservation_events',
        'audit_logs',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->assertRequiredSchema();
        $lastGateException = null;

        for ($attempt = 1; $attempt <= self::GATE_MAX_ATTEMPTS; $attempt++) {
            try {
                DB::transaction(fn () => $this->reconcileLocked(), 1);

                return;
            } catch (QueryException $exception) {
                if (! $this->isGateUnavailable($exception)) {
                    throw $exception;
                }

                $lastGateException = $exception;

                if ($attempt < self::GATE_MAX_ATTEMPTS) {
                    // Transaction attempt sudah rollback sehingga tidak ada gate awal yang ditahan saat jeda.
                    usleep(self::GATE_RETRY_DELAY_MICROSECONDS);
                }
            }
        }

        throw new RuntimeException(
            'Rekonsiliasi reservasi non-tahunan gagal memperoleh seluruh gate maintenance setelah '
            .self::GATE_MAX_ATTEMPTS.' percobaan. Hentikan writer lalu jalankan migration kembali.',
            previous: $lastGateException,
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->assertRequiredSchema();

        $hasEvents = DB::table('leave_balance_reservation_events')
            ->whereRaw("metadata->>'release_context' = ?", [self::RELEASE_CONTEXT])
            ->exists();
        $hasAudits = DB::table('audit_logs')
            ->where('event', 'LEAVE_BALANCE_RESERVATION_RELEASED')
            ->whereRaw("new_values->>'release_context' = ?", [self::RELEASE_CONTEXT])
            ->exists();

        if ($hasEvents || $hasAudits) {
            throw new RuntimeException(
                'Rollback dibatalkan karena bukti rekonsiliasi reservasi non-tahunan sudah tersimpan.',
            );
        }
    }

    private function assertRequiredSchema(): void
    {
        $missing = collect($this->requiredTables)
            ->reject(fn (string $table): bool => Schema::hasTable($table))
            ->values();

        if ($missing->isNotEmpty()) {
            throw new RuntimeException(
                'Migrasi rekonsiliasi reservasi non-tahunan membutuhkan tabel: '.$missing->implode(', ').'.',
            );
        }
    }

    /** Satu attempt utuh; kegagalan gate atau verifikasi me-rollback savepoint/transaction attempt. */
    private function reconcileLocked(): void
    {
        $this->lockReconciliationTables();
        $this->verifyExistingEvidence();
        $this->reconcilePositiveNetReservations();

        if ($this->nextPositiveNetGroups(null, null)->isNotEmpty()) {
            throw new RuntimeException('Migrasi gagal menetralkan seluruh reservasi aktif non-tahunan.');
        }

        $this->verifyExistingEvidence();
    }

    /**
     * NOWAIT mencegah attempt menunggu gate berikutnya sambil memegang gate sebelumnya.
     * Caller me-rollback seluruh attempt sebelum retry sehingga urutan writer lain tidak membentuk siklus.
     */
    private function lockReconciliationTables(): void
    {
        DB::statement('LOCK TABLE ref_jenis_cuti IN SHARE MODE NOWAIT');
        DB::statement('LOCK TABLE leave_requests IN SHARE MODE NOWAIT');
        DB::statement('LOCK TABLE leave_balance_reservation_events IN SHARE ROW EXCLUSIVE MODE NOWAIT');
        DB::statement('LOCK TABLE audit_logs IN SHARE ROW EXCLUSIVE MODE NOWAIT');
    }

    private function isGateUnavailable(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '55P03';
    }

    /** Memproses pasangan request-tahun secara keyset agar penggunaan memori tetap terbatas. */
    private function reconcilePositiveNetReservations(): void
    {
        $lastRequestId = null;
        $lastYear = null;

        do {
            $groups = $this->nextPositiveNetGroups($lastRequestId, $lastYear);

            if ($groups->isEmpty()) {
                return;
            }

            $this->appendCompensatingEvidence($groups);
            $last = $groups->last();
            $lastRequestId = (string) $last->leave_request_id;
            $lastYear = (int) $last->tahun;
        } while (true);
    }

    /** @return Collection<int, stdClass> Baris agregat request-tahun dari Query Builder. */
    private function nextPositiveNetGroups(?string $lastRequestId, ?int $lastYear): Collection
    {
        return DB::table('leave_balance_reservation_events as reservation_events')
            ->join('leave_requests', 'leave_requests.id', '=', 'reservation_events.leave_request_id')
            ->join('ref_jenis_cuti', 'ref_jenis_cuti.id', '=', 'leave_requests.jenis_cuti_id')
            ->whereIn('leave_requests.status', self::ACTIVE_STATUSES)
            ->whereRaw("ref_jenis_cuti.code IS DISTINCT FROM 'tahunan'")
            ->when($lastRequestId !== null && $lastYear !== null, function ($query) use ($lastRequestId, $lastYear): void {
                $query->where(function ($cursor) use ($lastRequestId, $lastYear): void {
                    $cursor
                        ->where('reservation_events.leave_request_id', '>', $lastRequestId)
                        ->orWhere(function ($sameRequest) use ($lastRequestId, $lastYear): void {
                            $sameRequest
                                ->where('reservation_events.leave_request_id', $lastRequestId)
                                ->where('reservation_events.tahun', '>', $lastYear);
                        });
                });
            })
            ->groupBy('reservation_events.leave_request_id', 'reservation_events.tahun')
            ->havingRaw('SUM(reservation_events.amount) > 0')
            ->orderBy('reservation_events.leave_request_id')
            ->orderBy('reservation_events.tahun')
            ->limit(self::BATCH_SIZE)
            ->get([
                'reservation_events.leave_request_id',
                'reservation_events.tahun',
                DB::raw('MIN(reservation_events.employee_id::text) AS employee_id'),
                DB::raw('COUNT(DISTINCT reservation_events.employee_id) AS employee_count'),
                DB::raw('MIN(leave_requests.employee_id::text) AS request_employee_id'),
                DB::raw('SUM(reservation_events.amount)::integer AS net_amount'),
                DB::raw('MIN(ref_jenis_cuti.code) AS leave_type_code'),
            ]);
    }

    /** @param Collection<int, stdClass> $groups */
    private function appendCompensatingEvidence(Collection $groups): void
    {
        $now = now();
        $events = [];

        foreach ($groups as $group) {
            $employeeCount = (int) $group->employee_count;
            $netAmount = (int) $group->net_amount;

            if ($employeeCount !== 1 || $netAmount <= 0) {
                throw new RuntimeException(
                    "Reservasi request {$group->leave_request_id} tahun {$group->tahun} tidak konsisten untuk dikompensasi.",
                );
            }

            if ((string) $group->employee_id !== (string) $group->request_employee_id) {
                throw new RuntimeException(
                    "Reservasi request {$group->leave_request_id} tahun {$group->tahun} memiliki employee event tidak sama dengan pemilik request.",
                );
            }

            $dedupKey = $this->dedupKey((string) $group->leave_request_id, (int) $group->tahun);
            $events[] = [
                'id' => (string) Str::uuid(),
                'employee_id' => (string) $group->employee_id,
                'leave_request_id' => (string) $group->leave_request_id,
                'leave_balance_id' => null,
                'tahun' => (int) $group->tahun,
                'event_type' => 'released',
                'amount' => -$netAmount,
                'reason' => self::REASON,
                'dedup_key' => $dedupKey,
                'metadata' => json_encode([
                    'release_context' => self::RELEASE_CONTEXT,
                    'source' => self::SYSTEM_SOURCE,
                    'released_days' => $netAmount,
                    'legacy_leave_type_code' => $group->leave_type_code,
                ], JSON_THROW_ON_ERROR),
                'created_by' => null,
                'occurred_at' => $now,
                'created_at' => $now,
            ];
        }

        DB::table('leave_balance_reservation_events')->insertOrIgnore($events);

        $dedupKeys = collect($events)->pluck('dedup_key')->all();
        $persistedEvents = DB::table('leave_balance_reservation_events')
            ->whereIn('dedup_key', $dedupKeys)
            ->get()
            ->keyBy('dedup_key');
        $auditRows = [];

        foreach ($groups as $group) {
            $dedupKey = $this->dedupKey((string) $group->leave_request_id, (int) $group->tahun);
            $event = $persistedEvents->get($dedupKey);
            $this->assertEventContract($event, $group);
            $auditRows[] = $this->auditRow($event, $group, $now);
        }

        DB::table('audit_logs')->insert($auditRows);
    }

    /** @return array<string, mixed> */
    private function auditRow(stdClass $event, stdClass $group, mixed $createdAt): array
    {
        $netAmount = (int) $group->net_amount;
        $payload = [
            'employee_id' => (string) $event->employee_id,
            'leave_request_id' => (string) $event->leave_request_id,
            'tahun' => (int) $event->tahun,
            'reservation_event_id' => (string) $event->id,
            'event_type' => 'released',
            'event_amount' => -$netAmount,
            'reason' => self::REASON,
            'release_context' => self::RELEASE_CONTEXT,
            'source' => self::SYSTEM_SOURCE,
            'legacy_leave_type_code' => $group->leave_type_code,
        ];

        return [
            'id' => (string) Str::uuid(),
            'user_id' => null,
            'user_name' => self::SYSTEM_ACTOR,
            'event' => 'LEAVE_BALANCE_RESERVATION_RELEASED',
            'auditable_type' => 'LeaveBalanceReservationEvent',
            'auditable_id' => (string) $event->id,
            'old_values' => json_encode(array_merge($payload, ['allocated_days' => $netAmount]), JSON_THROW_ON_ERROR),
            'new_values' => json_encode(array_merge($payload, [
                'allocated_days' => 0,
                'actor_type' => 'system',
            ]), JSON_THROW_ON_ERROR),
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => $createdAt,
        ];
    }

    private function verifyExistingEvidence(): void
    {
        $this->verifyExistingUpgradeEvents();
        $this->verifyExistingUpgradeAudits();
    }

    /** Memastikan setiap event dalam namespace upgrade memiliki tepat satu audit yang sah. */
    private function verifyExistingUpgradeEvents(): void
    {
        DB::table('leave_balance_reservation_events')
            ->where(function (Builder $events): void {
                $this->constrainUpgradeEventNamespace($events);
            })
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function (Collection $events): void {
                $eventIds = $events->pluck('id')->all();
                $neutralizations = $this->neutralizationEvidenceFor($events);
                $audits = DB::table('audit_logs')
                    ->whereIn('auditable_id', $eventIds)
                    ->get()
                    ->groupBy('auditable_id');

                foreach ($events as $event) {
                    $releasedDays = $this->assertExistingEventContract($event);
                    $this->assertExactNeutralization(
                        $event,
                        $releasedDays,
                        $neutralizations->get((string) $event->id),
                    );

                    $eventAudits = $audits->get($event->id, collect());

                    if ($eventAudits->count() !== 1) {
                        throw new RuntimeException("Audit kompensasi reservasi {$event->id} tidak lengkap atau duplikat.");
                    }

                    $this->assertAuditContract($eventAudits->sole(), $event, $releasedDays);
                }
            });
    }

    /**
     * @param  Collection<int, stdClass>  $events
     * @return Collection<string, stdClass>
     */
    private function neutralizationEvidenceFor(Collection $events): Collection
    {
        $eventIds = $events->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($eventIds === []) {
            return collect();
        }

        // Satu aggregate query memverifikasi maksimal satu batch event agar table gate tidak
        // ditahan oleh pola query per row saat volume bukti upgrade bertambah.
        return DB::table('leave_balance_reservation_events as upgrade_events')
            ->join('leave_balance_reservation_events as history_events', function (JoinClause $join): void {
                $join
                    ->on('history_events.leave_request_id', '=', 'upgrade_events.leave_request_id')
                    ->on('history_events.tahun', '=', 'upgrade_events.tahun');
            })
            ->join('leave_requests', 'leave_requests.id', '=', 'upgrade_events.leave_request_id')
            ->join('ref_jenis_cuti', 'ref_jenis_cuti.id', '=', 'leave_requests.jenis_cuti_id')
            ->whereIn('upgrade_events.id', $eventIds)
            ->groupBy('upgrade_events.id')
            ->orderBy('upgrade_events.id')
            ->get([
                'upgrade_events.id as event_id',
                DB::raw('COALESCE(SUM(history_events.amount), 0)::integer AS net_amount'),
                DB::raw('COALESCE(SUM(CASE WHEN history_events.id <> upgrade_events.id THEN history_events.amount ELSE 0 END), 0)::integer AS source_net_amount'),
                DB::raw('COUNT(DISTINCT history_events.employee_id)::integer AS employee_count'),
                DB::raw('MIN(history_events.employee_id::text) AS event_employee_id'),
                DB::raw('MIN(leave_requests.employee_id::text) AS request_employee_id'),
                DB::raw('MIN(ref_jenis_cuti.code) AS leave_type_code'),
            ])
            ->keyBy(fn (stdClass $evidence): string => (string) $evidence->event_id);
    }

    /** Memastikan setiap audit berkonteks upgrade menunjuk event namespace yang sah. */
    private function verifyExistingUpgradeAudits(): void
    {
        DB::table('audit_logs')
            ->where(function (Builder $audits): void {
                $audits
                    ->whereRaw("old_values->>'release_context' = ?", [self::RELEASE_CONTEXT])
                    ->orWhereRaw("new_values->>'release_context' = ?", [self::RELEASE_CONTEXT]);
            })
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function (Collection $audits): void {
                $eventIds = $audits->pluck('auditable_id')
                    ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
                    ->unique()
                    ->values()
                    ->all();
                $events = DB::table('leave_balance_reservation_events')
                    ->whereIn('id', $eventIds)
                    ->where(function (Builder $events): void {
                        $this->constrainUpgradeEventNamespace($events);
                    })
                    ->get()
                    ->keyBy('id');

                foreach ($audits as $audit) {
                    $event = $events->get($audit->auditable_id);

                    if ($event === null) {
                        throw new RuntimeException(
                            "Audit kompensasi reservasi tidak memiliki event pasangan dalam namespace upgrade: {$audit->id}.",
                        );
                    }

                    $releasedDays = $this->assertExistingEventContract($event);
                    $this->assertAuditContract($audit, $event, $releasedDays);
                }
            });
    }

    private function constrainUpgradeEventNamespace(Builder $query): void
    {
        $query
            ->whereRaw("metadata->>'release_context' = ?", [self::RELEASE_CONTEXT])
            ->orWhereRaw('dedup_key ~ ?', [self::DEDUP_NAMESPACE_REGEX]);
    }

    private function assertExistingEventContract(stdClass $event): int
    {
        $metadata = $event->metadata === null ? [] : $this->json((string) $event->metadata);
        $releasedDays = (int) ($metadata['released_days'] ?? 0);
        $expectedKey = $this->dedupKey((string) $event->leave_request_id, (int) $event->tahun);
        $hasLegacyLeaveTypeCode = array_key_exists('legacy_leave_type_code', $metadata);
        $legacyLeaveTypeCode = $metadata['legacy_leave_type_code'] ?? null;
        $matches = $event->event_type === 'released'
            && (int) $event->amount < 0
            && $releasedDays === -(int) $event->amount
            && $event->leave_balance_id === null
            && $event->created_by === null
            && $event->reason === self::REASON
            && $event->dedup_key === $expectedKey
            && ($metadata['release_context'] ?? null) === self::RELEASE_CONTEXT
            && ($metadata['source'] ?? null) === self::SYSTEM_SOURCE
            && $hasLegacyLeaveTypeCode
            && ($legacyLeaveTypeCode === null || (
                is_string($legacyLeaveTypeCode)
                && $legacyLeaveTypeCode !== ''
                && $legacyLeaveTypeCode !== 'tahunan'
            ));

        if (! $matches) {
            throw new RuntimeException("Bukti kompensasi reservasi {$event->id} tidak memenuhi kontrak upgrade.");
        }

        return $releasedDays;
    }

    /** Membuktikan event upgrade tepat menutup net sebelumnya tanpa over-release. */
    private function assertExactNeutralization(
        stdClass $event,
        int $releasedDays,
        ?stdClass $history,
    ): void {
        $metadata = $event->metadata === null ? [] : $this->json((string) $event->metadata);
        $matches = $history !== null
            && (int) $history->net_amount === 0
            && (int) $history->source_net_amount === $releasedDays
            && (int) $history->employee_count === 1
            && $history->event_employee_id === (string) $event->employee_id
            && $history->request_employee_id === (string) $event->employee_id
            && $history->leave_type_code !== 'tahunan'
            && $history->leave_type_code === ($metadata['legacy_leave_type_code'] ?? null);

        if (! $matches) {
            throw new RuntimeException(
                "Bukti kompensasi reservasi {$event->id} tidak menetralkan histori request-tahun secara tepat.",
            );
        }
    }

    private function assertEventContract(?stdClass $event, stdClass $group): void
    {
        if ($event === null) {
            throw new RuntimeException('Event kompensasi reservasi gagal ditulis.');
        }

        $metadata = $this->json((string) $event->metadata);
        $matches = $event->employee_id === (string) $group->employee_id
            && $event->leave_request_id === (string) $group->leave_request_id
            && (int) $event->tahun === (int) $group->tahun
            && $event->event_type === 'released'
            && (int) $event->amount === -(int) $group->net_amount
            && $event->leave_balance_id === null
            && $event->created_by === null
            && $event->reason === self::REASON
            && ($metadata['release_context'] ?? null) === self::RELEASE_CONTEXT
            && ($metadata['source'] ?? null) === self::SYSTEM_SOURCE
            && (int) ($metadata['released_days'] ?? 0) === (int) $group->net_amount;

        if (! $matches) {
            throw new RuntimeException("Event kompensasi {$event->dedup_key} bertabrakan dengan kontrak upgrade.");
        }
    }

    private function assertAuditContract(stdClass $audit, stdClass $event, int $releasedDays): void
    {
        $oldValues = $this->json((string) $audit->old_values);
        $newValues = $this->json((string) $audit->new_values);
        $eventMetadata = $event->metadata === null ? [] : $this->json((string) $event->metadata);
        $matches = $audit->user_id === null
            && $audit->user_name === self::SYSTEM_ACTOR
            && $audit->event === 'LEAVE_BALANCE_RESERVATION_RELEASED'
            && $audit->auditable_type === 'LeaveBalanceReservationEvent'
            && $audit->auditable_id === $event->id
            && ($oldValues['reservation_event_id'] ?? null) === $event->id
            && ($newValues['reservation_event_id'] ?? null) === $event->id
            && ($oldValues['employee_id'] ?? null) === $event->employee_id
            && ($newValues['employee_id'] ?? null) === $event->employee_id
            && ($oldValues['leave_request_id'] ?? null) === $event->leave_request_id
            && ($newValues['leave_request_id'] ?? null) === $event->leave_request_id
            && (int) ($oldValues['tahun'] ?? 0) === (int) $event->tahun
            && (int) ($newValues['tahun'] ?? 0) === (int) $event->tahun
            && ($oldValues['event_type'] ?? null) === 'released'
            && ($newValues['event_type'] ?? null) === 'released'
            && (int) ($oldValues['event_amount'] ?? 0) === (int) $event->amount
            && (int) ($newValues['event_amount'] ?? 0) === (int) $event->amount
            && array_key_exists('reason', $oldValues)
            && array_key_exists('reason', $newValues)
            && ($oldValues['reason'] ?? null) === $event->reason
            && ($newValues['reason'] ?? null) === $event->reason
            && (int) ($oldValues['allocated_days'] ?? -1) === $releasedDays
            && (int) ($newValues['allocated_days'] ?? -1) === 0
            && ($oldValues['release_context'] ?? null) === self::RELEASE_CONTEXT
            && ($newValues['release_context'] ?? null) === self::RELEASE_CONTEXT
            && ($oldValues['source'] ?? null) === self::SYSTEM_SOURCE
            && ($newValues['source'] ?? null) === self::SYSTEM_SOURCE
            && array_key_exists('legacy_leave_type_code', $oldValues)
            && array_key_exists('legacy_leave_type_code', $newValues)
            && ($oldValues['legacy_leave_type_code'] ?? null) === ($eventMetadata['legacy_leave_type_code'] ?? null)
            && ($newValues['legacy_leave_type_code'] ?? null) === ($eventMetadata['legacy_leave_type_code'] ?? null)
            && ($newValues['actor_type'] ?? null) === 'system';

        if (! $matches) {
            throw new RuntimeException("Audit kompensasi reservasi {$event->id} tidak memenuhi kontrak upgrade.");
        }
    }

    /** @return array<string, mixed> */
    private function json(string $value): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function dedupKey(string $leaveRequestId, int $year): string
    {
        return "leave_reservation:{$leaveRequestId}:released:nonannual_upgrade:{$year}";
    }
};
