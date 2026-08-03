<?php

namespace App\Services\Cuti;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mengelola alokasi sementara saldo Cuti Tahunan untuk pengajuan yang belum final.
 *
 * Ledger reservasi ini tidak pernah mengubah `leave_balances.sisa` atau `terpakai`.
 * Setiap mutasi mengunci ringkasan saldo pegawai/tahun, sehingga beberapa submit
 * tidak dapat melewati hak yang masih tersedia secara bersamaan.
 */
class LeaveBalanceReservationService
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    /**
     * Mengembalikan saldo yang dapat dipakai untuk submit setelah alokasi pengajuan aktif.
     * Pengajuan yang sedang dikirim ulang dikecualikan agar ia hanya bersaing dengan request lain.
     */
    public function availableForSubmission(
        Employee|string $employee,
        int $tahun,
        Carbon $asOf,
        ?LeaveRequest $excludingLeaveRequest = null,
    ): int {
        $employeeModel = $this->resolveEmployee($employee);
        $saldoAktual = $this->balances->availableFor($employeeModel, $tahun, $asOf);
        $dialokasikan = $this->activeReservedTotal($employeeModel->id, $tahun);

        if ($excludingLeaveRequest !== null && $excludingLeaveRequest->employee_id === $employeeModel->id) {
            $dialokasikan -= $this->reservedForRequestYear($excludingLeaveRequest->id, $tahun);
        }

        return max(0, $saldoAktual - max(0, $dialokasikan));
    }

    /**
     * Menulis alokasi awal untuk pengajuan tahunan yang baru dibuat.
     *
     * @throws ValidationException bila saldo setelah pengajuan aktif lain tidak mencukupi
     */
    public function reserveForNewRequest(LeaveRequest $leaveRequest, ?User $actor = null): void
    {
        $this->loadLeaveRelations($leaveRequest);

        if (! $this->isAnnualLeave($leaveRequest)) {
            return;
        }

        $this->assertRequestHasActiveStatus($leaveRequest);

        DB::transaction(function () use ($leaveRequest, $actor): void {
            $employee = $this->lockEmployee($leaveRequest->employee_id);
            $tahun = $leaveRequest->tanggal_mulai->year;
            $balance = $this->lockBalances($employee, [$tahun => $leaveRequest->tanggal_mulai])[$tahun] ?? null;

            if ($balance === null) {
                throw $this->insufficientBalance($leaveRequest->jumlah_hari_kerja, 0);
            }

            $reserved = $this->activeReservedTotal($employee->id, $tahun);
            $actual = $this->balances->availableFor($employee, $tahun, $leaveRequest->tanggal_mulai);
            $available = max(0, $actual - $reserved);
            $requested = (int) $leaveRequest->jumlah_hari_kerja;

            if ($requested > $available) {
                throw $this->insufficientBalance($requested, $available);
            }

            $this->appendEvent(
                leaveRequest: $leaveRequest,
                balance: $balance,
                tahun: $tahun,
                eventType: LeaveBalanceReservationEvent::EVENT_RESERVED,
                amount: $requested,
                reservationBefore: 0,
                reservationAfter: $requested,
                actor: $actor,
                dedupKey: "leave_reservation:{$leaveRequest->id}:reserved",
                reason: 'Hak cuti tahunan dialokasikan untuk pengajuan aktif.',
                metadata: ['requested_days' => $requested],
            );
        });
    }

    /**
     * Menghitung ulang alokasi pada resubmit tanpa membuat request baru.
     * Perpindahan tahun melepas alokasi lama dan menulis alokasi tahun baru dalam transaksi yang sama.
     */
    public function adjustForResubmission(
        LeaveRequest $leaveRequest,
        Carbon $newStartDate,
        int $newWorkdays,
        ?User $actor = null,
    ): void {
        $this->loadLeaveRelations($leaveRequest);

        if (! $this->isAnnualLeave($leaveRequest)) {
            return;
        }

        $this->assertRequestHasActiveStatus($leaveRequest);

        DB::transaction(function () use ($leaveRequest, $newStartDate, $newWorkdays, $actor): void {
            $employee = $this->lockEmployee($leaveRequest->employee_id);
            $existingByYear = $this->reservedByYearForRequest($leaveRequest->id);
            $newYear = $newStartDate->year;
            $datesByYear = [$newYear => $newStartDate];

            foreach (array_keys($existingByYear) as $year) {
                $datesByYear[(int) $year] = Carbon::create((int) $year, 1, 1)->startOfDay();
            }

            $balances = $this->lockBalances($employee, $datesByYear);
            $existingInNewYear = $existingByYear[$newYear] ?? 0;
            $otherReservations = $this->activeReservedTotal($employee->id, $newYear) - $existingInNewYear;
            $newBalance = $balances[$newYear] ?? null;
            $actual = $newBalance === null
                ? 0
                : $this->balances->availableFor($employee, $newYear, $newStartDate);
            $available = max(0, $actual - max(0, $otherReservations));

            if ($newWorkdays > $available) {
                throw $this->insufficientBalance($newWorkdays, $available);
            }

            $sequence = $this->nextAdjustmentSequence($leaveRequest->id);

            foreach ($existingByYear as $year => $existingAmount) {
                $year = (int) $year;

                if ($year === $newYear) {
                    continue;
                }

                if ($existingAmount === 0) {
                    continue;
                }

                $this->appendEvent(
                    leaveRequest: $leaveRequest,
                    balance: $balances[$year] ?? null,
                    tahun: $year,
                    eventType: LeaveBalanceReservationEvent::EVENT_ADJUSTED,
                    amount: -$existingAmount,
                    reservationBefore: $existingAmount,
                    reservationAfter: 0,
                    actor: $actor,
                    dedupKey: "leave_reservation:{$leaveRequest->id}:adjusted:{$sequence}:{$year}",
                    reason: 'Alokasi tahun sebelumnya dilepas karena tanggal pengajuan diperbaiki.',
                    metadata: [
                        'new_year' => $newYear,
                        'new_requested_days' => $newWorkdays,
                    ],
                );
            }

            $eventType = $existingByYear === []
                ? LeaveBalanceReservationEvent::EVENT_RESERVED
                : LeaveBalanceReservationEvent::EVENT_ADJUSTED;
            $dedupKey = $eventType === LeaveBalanceReservationEvent::EVENT_RESERVED
                ? "leave_reservation:{$leaveRequest->id}:reserved"
                : "leave_reservation:{$leaveRequest->id}:adjusted:{$sequence}:{$newYear}";

            $this->appendEvent(
                leaveRequest: $leaveRequest,
                balance: $newBalance,
                tahun: $newYear,
                eventType: $eventType,
                amount: $newWorkdays - $existingInNewYear,
                reservationBefore: $existingInNewYear,
                reservationAfter: $newWorkdays,
                actor: $actor,
                dedupKey: $dedupKey,
                reason: $eventType === LeaveBalanceReservationEvent::EVENT_RESERVED
                    ? 'Hak cuti tahunan dialokasikan saat pengajuan dikirim ulang.'
                    : 'Alokasi cuti tahunan disesuaikan saat pengajuan dikirim ulang.',
                metadata: [
                    'old_year' => $existingByYear === [] ? null : array_key_first($existingByYear),
                    'old_requested_days' => $existingInNewYear,
                    'new_requested_days' => $newWorkdays,
                ],
            );
        });
    }

    /**
     * Menghapus alokasi aktif setelah final approval, tepat ketika pemotongan saldo final dilakukan.
     */
    public function convertForFinalApproval(LeaveRequest $leaveRequest, ?User $actor = null): void
    {
        $this->releaseReservation($leaveRequest, $actor, LeaveBalanceReservationEvent::EVENT_CONVERTED);
    }

    /** Menghapus alokasi aktif setelah pengajuan berstatus Tidak Disetujui. */
    public function releaseForNotApproved(LeaveRequest $leaveRequest, ?User $actor = null): void
    {
        $this->releaseReservation($leaveRequest, $actor, LeaveBalanceReservationEvent::EVENT_RELEASED);
    }

    /**
     * Melepas seluruh reservasi setelah hak cuti dicatat sebagai penangguhan dinas terminal.
     * Audit reservasi sengaja tidak ditulis karena orkestrasi terminal menulis satu audit domain terpadu.
     */
    public function releaseForDutyPostponement(
        LeaveRequest $leaveRequest,
        User $actor,
    ): ?LeaveBalanceReservationEvent {
        $this->loadLeaveRelations($leaveRequest);
        $tahun = $leaveRequest->tanggal_mulai->year;
        $requestedDays = (int) $leaveRequest->jumlah_hari_kerja;

        if ($leaveRequest->jenisCuti?->code !== 'tahunan') {
            throw ValidationException::withMessages([
                'jenis_cuti' => 'Reservasi penangguhan dinas hanya dapat dilepas untuk cuti tahunan.',
            ]);
        }

        if ($requestedDays <= 0) {
            throw ValidationException::withMessages([
                'jumlah_hari' => 'Jumlah hari kerja penangguhan dinas harus lebih dari nol.',
            ]);
        }

        if ($leaveRequest->tanggal_selesai->year !== $tahun) {
            throw ValidationException::withMessages([
                'tanggal_selesai' => 'Reservasi penangguhan dinas lintas tahun tidak dapat dilepas dalam satu event.',
            ]);
        }

        return DB::transaction(function () use ($leaveRequest, $actor, $tahun, $requestedDays): LeaveBalanceReservationEvent {
            $employee = $this->lockEmployee($leaveRequest->employee_id);
            $balance = $this->lockBalances($employee, [$tahun => $leaveRequest->tanggal_mulai])[$tahun] ?? null;

            if ($balance === null) {
                throw ValidationException::withMessages([
                    'saldo' => 'Saldo tahun sumber tidak ditemukan untuk pelepasan reservasi penangguhan dinas.',
                ]);
            }

            // Lock seluruh event request setelah saldo agar retry dan net reservasi dibaca dari snapshot yang sama.
            $events = LeaveBalanceReservationEvent::query()
                ->where('leave_request_id', $leaveRequest->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $dedupKey = "leave_reservation:{$leaveRequest->id}:released:duty_postponement:{$tahun}";
            $existing = $events->firstWhere('dedup_key', $dedupKey);

            if ($existing instanceof LeaveBalanceReservationEvent) {
                $this->assertDutyPostponementReleaseContract(
                    $existing,
                    $leaveRequest,
                    $balance,
                    $actor,
                    $tahun,
                    $requestedDays,
                );

                return $existing;
            }

            $activeReserved = (int) $events->sum('amount');

            if ($activeReserved !== $requestedDays) {
                throw ValidationException::withMessages([
                    'saldo' => "Reservasi aktif harus tepat {$requestedDays} hari sebelum penangguhan dinas, ditemukan {$activeReserved} hari.",
                ]);
            }

            $event = LeaveBalanceReservationEvent::query()->firstOrCreate(
                ['dedup_key' => $dedupKey],
                [
                    'employee_id' => $leaveRequest->employee_id,
                    'leave_request_id' => $leaveRequest->id,
                    'leave_balance_id' => $balance->id,
                    'tahun' => $tahun,
                    'event_type' => LeaveBalanceReservationEvent::EVENT_RELEASED,
                    'amount' => -$activeReserved,
                    'reason' => 'Reservasi cuti tahunan dilepas setelah penangguhan dinas terminal dicatat.',
                    'metadata' => ['release_context' => 'duty_postponement_terminal'],
                    'created_by' => $actor->id,
                    'occurred_at' => Carbon::now(),
                ],
            );
            $this->assertDutyPostponementReleaseContract(
                $event,
                $leaveRequest,
                $balance,
                $actor,
                $tahun,
                $requestedDays,
            );

            return $event;
        });
    }

    private function releaseReservation(LeaveRequest $leaveRequest, ?User $actor, string $eventType): void
    {
        $this->loadLeaveRelations($leaveRequest);

        if (! $this->isAnnualLeave($leaveRequest)) {
            return;
        }

        DB::transaction(function () use ($leaveRequest, $actor, $eventType): void {
            $employee = $this->lockEmployee($leaveRequest->employee_id);
            $reservedByYear = $this->reservedByYearForRequest($leaveRequest->id);

            if ($reservedByYear === []) {
                return;
            }

            $datesByYear = collect(array_keys($reservedByYear))
                ->mapWithKeys(fn (int|string $year): array => [(int) $year => Carbon::create((int) $year, 1, 1)->startOfDay()])
                ->all();
            $balances = $this->lockBalances($employee, $datesByYear);

            foreach ($reservedByYear as $tahun => $reserved) {
                $tahun = (int) $tahun;

                if ($reserved === 0) {
                    continue;
                }

                $label = $eventType === LeaveBalanceReservationEvent::EVENT_CONVERTED
                    ? 'Alokasi cuti tahunan dikonversi menjadi pemakaian setelah persetujuan final.'
                    : 'Alokasi cuti tahunan dilepas karena pengajuan tidak disetujui.';

                $this->appendEvent(
                    leaveRequest: $leaveRequest,
                    balance: $balances[$tahun] ?? null,
                    tahun: $tahun,
                    eventType: $eventType,
                    amount: -$reserved,
                    reservationBefore: $reserved,
                    reservationAfter: 0,
                    actor: $actor,
                    dedupKey: "leave_reservation:{$leaveRequest->id}:{$eventType}:{$tahun}",
                    reason: $label,
                    metadata: [
                        'requested_days' => (int) $leaveRequest->jumlah_hari_kerja,
                    ],
                );
            }
        });
    }

    /**
     * @param  array<int, Carbon>  $datesByYear
     * @return array<int, LeaveBalance>
     */
    private function lockBalances(Employee $employee, array $datesByYear): array
    {
        ksort($datesByYear);
        $balances = [];

        foreach ($datesByYear as $tahun => $asOf) {
            // Menjaga entitlement lazy tetap konsisten dengan alur submit yang sudah ada.
            $this->balances->availableFor($employee, (int) $tahun, $asOf);
            $balance = LeaveBalance::query()
                ->where('employee_id', $employee->id)
                ->where('tahun', $tahun)
                ->lockForUpdate()
                ->first();

            if ($balance !== null) {
                $balances[(int) $tahun] = $balance;
            }
        }

        return $balances;
    }

    private function lockEmployee(string $employeeId): Employee
    {
        return Employee::query()->whereKey($employeeId)->lockForUpdate()->firstOrFail();
    }

    private function activeReservedTotal(string $employeeId, int $tahun): int
    {
        return max(0, (int) LeaveBalanceReservationEvent::query()
            ->forActiveRequests()
            ->where('employee_id', $employeeId)
            ->where('tahun', $tahun)
            ->sum('amount'));
    }

    /** @return array<int, int> */
    private function reservedByYearForRequest(string $leaveRequestId): array
    {
        return LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequestId)
            ->selectRaw('tahun, SUM(amount) as amount')
            ->groupBy('tahun')
            ->pluck('amount', 'tahun')
            ->map(fn (int|string $amount): int => (int) $amount)
            ->all();
    }

    private function reservedForRequestYear(string $leaveRequestId, int $tahun): int
    {
        return (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequestId)
            ->where('tahun', $tahun)
            ->sum('amount');
    }

    private function nextAdjustmentSequence(string $leaveRequestId): int
    {
        return LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequestId)
            ->where('event_type', LeaveBalanceReservationEvent::EVENT_ADJUSTED)
            ->count() + 1;
    }

    /** @param array<string, mixed> $metadata */
    private function appendEvent(
        LeaveRequest $leaveRequest,
        ?LeaveBalance $balance,
        int $tahun,
        string $eventType,
        int $amount,
        int $reservationBefore,
        int $reservationAfter,
        ?User $actor,
        string $dedupKey,
        string $reason,
        array $metadata,
    ): void {
        $authenticatedActor = Auth::user();
        $auditActor = $actor ?? ($authenticatedActor instanceof User ? $authenticatedActor : null);

        $event = LeaveBalanceReservationEvent::create([
            'employee_id' => $leaveRequest->employee_id,
            'leave_request_id' => $leaveRequest->id,
            'leave_balance_id' => $balance?->id,
            'tahun' => $tahun,
            'event_type' => $eventType,
            'amount' => $amount,
            'reason' => $reason,
            'dedup_key' => $dedupKey,
            'metadata' => $metadata,
            'created_by' => $auditActor?->id,
            'occurred_at' => Carbon::now(),
        ]);

        $auditPayload = array_merge([
            'employee_id' => $leaveRequest->employee_id,
            'leave_request_id' => $leaveRequest->id,
            'leave_balance_id' => $balance?->id,
            'tahun' => $tahun,
            'reservation_event_id' => $event->id,
            'event_type' => $eventType,
            'event_amount' => $amount,
            'reason' => $reason,
        ], $metadata);

        // Audit reservasi bersifat fail-closed: tanpa jejak audit, event reservasi tidak boleh commit.
        AuditLog::create([
            'user_id' => $auditActor?->id,
            'user_name' => $auditActor?->name,
            'event' => $this->auditEventType($eventType),
            'auditable_type' => 'LeaveBalanceReservationEvent',
            'auditable_id' => $event->id,
            'old_values' => array_merge($auditPayload, ['allocated_days' => $reservationBefore]),
            'new_values' => array_merge($auditPayload, ['allocated_days' => $reservationAfter]),
        ]);
    }

    private function auditEventType(string $eventType): string
    {
        return match ($eventType) {
            LeaveBalanceReservationEvent::EVENT_RESERVED => 'LEAVE_BALANCE_RESERVED',
            LeaveBalanceReservationEvent::EVENT_ADJUSTED => 'LEAVE_BALANCE_RESERVATION_ADJUSTED',
            LeaveBalanceReservationEvent::EVENT_CONVERTED => 'LEAVE_BALANCE_RESERVATION_CONVERTED',
            LeaveBalanceReservationEvent::EVENT_RELEASED => 'LEAVE_BALANCE_RESERVATION_RELEASED',
        };
    }

    /** Retry hanya idempoten bila event existing masih memenuhi kontrak release terminal. */
    private function assertDutyPostponementReleaseContract(
        LeaveBalanceReservationEvent $event,
        LeaveRequest $leaveRequest,
        LeaveBalance $balance,
        User $actor,
        int $tahun,
        int $requestedDays,
    ): void {
        $matches = $event->event_type === LeaveBalanceReservationEvent::EVENT_RELEASED
            && $event->employee_id === $leaveRequest->employee_id
            && $event->leave_request_id === $leaveRequest->id
            && $event->leave_balance_id === $balance->id
            && $event->tahun === $tahun
            && $event->amount === -$requestedDays
            && $event->created_by === $actor->id
            && ($event->metadata['release_context'] ?? null) === 'duty_postponement_terminal';

        if (! $matches) {
            throw ValidationException::withMessages([
                'leave_request' => 'Event pelepasan reservasi penangguhan dinas existing tidak sesuai kontrak pengajuan.',
            ]);
        }
    }

    private function insufficientBalance(int $requested, int $available): ValidationException
    {
        return ValidationException::withMessages([
            'tanggal_selesai' => "Saldo cuti tahunan tidak mencukupi setelah alokasi pengajuan aktif. Sisa yang dapat diajukan {$available} hari, sedangkan pengajuan membutuhkan {$requested} hari kerja.",
        ]);
    }

    private function assertRequestHasActiveStatus(LeaveRequest $leaveRequest): void
    {
        if (! in_array($leaveRequest->status, LeaveBalanceReservationEvent::activeRequestStatuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Reservasi hanya dapat dibuat untuk pengajuan cuti tahunan yang masih aktif.',
            ]);
        }
    }

    private function isAnnualLeave(LeaveRequest $leaveRequest): bool
    {
        return (bool) $leaveRequest->jenisCuti?->mengurangi_saldo_tahunan;
    }

    private function loadLeaveRelations(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing('jenisCuti');
    }

    private function resolveEmployee(Employee|string $employee): Employee
    {
        return $employee instanceof Employee
            ? $employee
            : Employee::query()->findOrFail($employee);
    }
}
