<?php

namespace App\Services\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStatusTransition;
use App\Models\EwsAlert;
use App\Models\RefStatusPegawai;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Menjadwalkan dan menerapkan transisi status kepegawaian (K-STATUS-06).
 *
 * Tanggal efektif di masa depan disimpan sebagai transisi terjadwal TANPA mengubah
 * snapshot/akses. Saat jatuh tempo (Asia/Makassar), scheduler memanggil writer yang
 * sama dengan jalur langsung (deactivate/restore/status) — satu policy, satu audit,
 * dan idempoten terhadap retry/double worker.
 */
class EmployeeStatusTransitionService
{
    public function __construct(
        private readonly EmployeeStatusLifecycleService $lifecycle,
    ) {}

    public function schedule(
        Employee $employee,
        RefStatusPegawai $targetStatus,
        string $tanggalEfektif,
        string $kind,
        ?string $keterangan = null,
        ?string $statusNote = null,
        ?Document $document = null,
        EmployeeStatusActorContext|Request|null $actorContext = null,
        ?EwsAlert $sourceEwsAlert = null,
    ): EmployeeStatusTransition {
        return $this->scheduleWithDocumentFactory(
            $employee,
            $targetStatus,
            $tanggalEfektif,
            $kind,
            $keterangan,
            $statusNote,
            static fn (Employee $lockedEmployee, RefStatusPegawai $lockedTargetStatus): ?Document => $document,
            $actorContext,
            $sourceEwsAlert,
        );
    }

    /**
     * Mengunci employee dan memeriksa pending sebelum factory membuat metadata dokumen.
     * Urutan ini mencegah dua FK documents memegang key-share lalu saling meng-upgrade
     * ke lock employee ketika dua attachment dijadwalkan secara paralel.
     *
     * @param  Closure(Employee, RefStatusPegawai): (?Document)  $documentFactory
     */
    public function scheduleWithDocumentFactory(
        Employee $employee,
        RefStatusPegawai $targetStatus,
        string $tanggalEfektif,
        string $kind,
        ?string $keterangan,
        ?string $statusNote,
        Closure $documentFactory,
        EmployeeStatusActorContext|Request|null $actorContext = null,
        ?EwsAlert $sourceEwsAlert = null,
    ): EmployeeStatusTransition {

        if (! $actorContext instanceof EmployeeStatusActorContext && ! $actorContext instanceof Request) {
            throw ValidationException::withMessages([
                'actor' => 'Provenance aktor wajib disertakan saat membuat jadwal status.',
            ]);
        }

        try {
            return DB::transaction(function () use ($employee, $targetStatus, $tanggalEfektif, $kind, $keterangan, $statusNote, $documentFactory, $actorContext, $sourceEwsAlert): EmployeeStatusTransition {
                // Lock pegawai menyerialkan scheduler creation pada pegawai yang sama;
                // target status selalu dikunci sesudahnya agar tidak membentuk siklus
                // dengan writer lifecycle lain. Partial unique index tetap pagar terakhir.
                $lockedEmployee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $lockedTargetStatus = $this->lifecycle->lockActiveTargetStatus($targetStatus);
                $intent = $this->intentForKind($kind);
                $authorizationAction = $this->authorizationActionForKind($kind);
                $permission = $this->lifecycle->authorizationPermission($lockedEmployee, $lockedTargetStatus, $intent);
                $resolvedActorContext = $actorContext instanceof Request
                    ? EmployeeStatusActorContext::capture($actorContext, $permission, $authorizationAction)
                    : $actorContext;
                $resolvedActorContext->assertAuthorizes($permission, $authorizationAction);

                $lockedSourceAlert = $this->lockAndValidateEwsSource($lockedEmployee, $kind, $sourceEwsAlert);

                if (! $this->lifecycle->shouldMutateLocked($lockedEmployee, $lockedTargetStatus, $intent)) {
                    throw ValidationException::withMessages([
                        'status_pegawai_id' => 'Pegawai sudah berstatus '.$lockedTargetStatus->nama.'.',
                    ]);
                }

                if ($this->isScheduled($lockedEmployee, $tanggalEfektif)) {
                    throw $this->duplicateScheduleException();
                }

                // Factory baru dijalankan setelah lock dan recheck agar INSERT documents
                // tidak mendahului lock employee melalui constraint FK.
                $document = $documentFactory($lockedEmployee, $lockedTargetStatus);
                $this->assertValidDocument($lockedEmployee, $kind, $document);

                // Provenance tidak mass-assignable; hanya jalur create terkontrol ini
                // yang boleh menulis snapshot sebelum invariant immutable DB aktif.
                $transition = new EmployeeStatusTransition;
                $transition->forceFill(array_merge([
                    'employee_id' => $lockedEmployee->id,
                    'status_pegawai_id' => $lockedTargetStatus->id,
                    'tanggal_efektif' => $tanggalEfektif,
                    'kind' => $kind,
                    'keterangan' => $keterangan,
                    'status_note' => $statusNote,
                    'document_id' => $document?->id,
                    'source_ews_alert_id' => $lockedSourceAlert?->id,
                ], $resolvedActorContext->transitionAttributes()))->saveOrFail();

                return $transition;
            });
        } catch (UniqueConstraintViolationException) {
            // Race yang lolos dari pengecekan service diterjemahkan ke kontrak validasi
            // yang sama; detail constraint PostgreSQL tidak dibocorkan ke pengguna.
            throw $this->duplicateScheduleException();
        }
    }

    public function isScheduled(Employee $employee, string $tanggalEfektif): bool
    {
        return EmployeeStatusTransition::query()
            ->where('employee_id', $employee->id)
            ->where('tanggal_efektif', $tanggalEfektif)
            ->where('is_applied', false)
            ->exists();
    }

    /**
     * Apakah tanggal efektif berada pada masa depan (zona Asia/Makassar)?
     */
    public function isFuture(string $tanggalEfektif): bool
    {
        // Kolom dan kontrak bisnis menyimpan tanggal tanpa waktu. Normalisasi kedua
        // sisi ke awal hari WITA agar datetime pada tanggal yang sama tidak dijadwalkan.
        return Carbon::parse($tanggalEfektif, 'Asia/Makassar')
            ->setTimezone('Asia/Makassar')
            ->startOfDay()
            ->isAfter(now('Asia/Makassar')->startOfDay());
    }

    /**
     * Menerapkan semua transisi yang sudah jatuh tempo. Idempoten & concurrency-safe:
     * baris transisi dikunci, is_applied dicek ulang, dan setiap worker memanggil
     * writer status yang sama seperti jalur langsung.
     */
    public function applyDue(?string $asOf = null): int
    {
        $today = $asOf ?? now('Asia/Makassar')->toDateString();
        $applied = 0;

        $lastDate = null;
        $lastId = null;

        do {
            $query = $this->eligibleDueQuery($today);

            if ($lastDate !== null && $lastId !== null) {
                // Keyset pagination mempertahankan urutan tanggal + UUID tanpa offset
                // pada dataset yang menyusut ketika setiap transisi berhasil diterapkan.
                $query->where(function ($cursor) use ($lastDate, $lastId): void {
                    $cursor->where('tanggal_efektif', '>', $lastDate)
                        ->orWhere(function ($sameDate) use ($lastDate, $lastId): void {
                            $sameDate->where('tanggal_efektif', $lastDate)
                                ->where('id', '>', $lastId);
                        });
                });
            }

            $transitions = $query
                ->orderBy('tanggal_efektif')
                ->orderBy('id')
                ->limit(50)
                ->get();

            foreach ($transitions as $transition) {
                if ($this->applyOne($transition)) {
                    $applied++;
                }
            }

            $lastTransition = $transitions->last();
            $lastDate = $lastTransition?->tanggal_efektif->toDateString();
            $lastId = $lastTransition?->id;
        } while ($transitions->isNotEmpty());

        return $applied;
    }

    /** Menghitung pekerjaan jatuh tempo yang masih perlu apply atau pemulihan notifikasi. */
    public function remainingDueCount(?string $asOf = null): int
    {
        $today = $asOf ?? now('Asia/Makassar')->toDateString();

        return $this->eligibleDueQuery($today)->count();
    }

    /** @return Builder<EmployeeStatusTransition> */
    private function eligibleDueQuery(string $today): Builder
    {
        return EmployeeStatusTransition::query()
            ->where('tanggal_efektif', '<=', $today)
            ->where(function ($eligible): void {
                $eligible->where('is_applied', false)
                    ->orWhere(function ($recovery): void {
                        $recovery->where('is_applied', true)
                            ->where('kind', EmployeeStatusTransition::KIND_EWS_RETIREMENT)
                            ->whereHas('sourceEwsAlert', fn ($source): mixed => $source
                                ->whereNull('lifecycle_notified_at')
                                ->whereNull('lifecycle_notification_superseded_at'));
                    });
            });
    }

    private function applyOne(EmployeeStatusTransition $transition): bool
    {
        $result = null;
        $context = EmployeeStatusLifecycleService::CONTEXT_GENERIC;
        $intent = EmployeeStatusLifecycleService::INTENT_GENERIC;
        $needsEwsNotificationRecovery = false;

        try {
            $changed = DB::transaction(function () use ($transition, &$result, &$context, &$intent, &$needsEwsNotificationRecovery): bool {
                // Lock baris transisi: worker kedua yang mengejar transisi yang sama
                // akan melihat is_applied=true setelah lock dan berhenti.
                $locked = EmployeeStatusTransition::query()
                    ->whereKey($transition->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->is_applied) {
                    $needsEwsNotificationRecovery = $locked->kind === EmployeeStatusTransition::KIND_EWS_RETIREMENT;

                    return false;
                }

                $context = match ($locked->kind) {
                    EmployeeStatusTransition::KIND_DEACTIVATE => EmployeeStatusLifecycleService::CONTEXT_DEACTIVATE,
                    EmployeeStatusTransition::KIND_RESTORE => EmployeeStatusLifecycleService::CONTEXT_RESTORE,
                    EmployeeStatusTransition::KIND_STATUS => EmployeeStatusLifecycleService::CONTEXT_GENERIC,
                    EmployeeStatusTransition::KIND_EWS_RETIREMENT => EmployeeStatusLifecycleService::CONTEXT_EWS_RETIREMENT,
                    default => throw new RuntimeException("Transisi status jenis '{$locked->kind}' tidak dikenal."),
                };
                $intent = $this->intentForKind($locked->kind);
                $actorContext = EmployeeStatusActorContext::fromTransition($locked);
                $employee = Employee::query()
                    ->whereKey($locked->employee_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                // Urutan apply due: transition -> pegawai -> target status. Target
                // direload sebelum permission, no-op, factory dokumen, dan FK dipakai.
                $targetStatus = $this->lifecycle->lockActiveTargetStatus($locked->status_pegawai_id);
                $tanggal = $locked->tanggal_efektif->toDateString();
                $document = $locked->document;
                $this->assertValidDocument($employee, $locked->kind, $document);

                // Generic target yang sudah dicapai writer lain tetap diselesaikan sebagai
                // no-op Task 3. Intent khusus tetap melempar bila source state tidak sah.
                if (! $this->lifecycle->shouldMutateLocked($employee, $targetStatus, $intent)) {
                    $locked->update([
                        'is_applied' => true,
                        'applied_at' => now(),
                    ]);

                    return false;
                }

                $requiredPermission = $this->lifecycle->authorizationPermission($employee, $targetStatus, $intent);
                // Bandingkan klasifikasi source→target terkunci dengan keputusan schedule;
                // permission live tidak pernah dibaca ulang pada fase due.
                $actorContext->assertAuthorizes(
                    $requiredPermission,
                    $this->authorizationActionForKind($locked->kind),
                );

                $request = Request::create('/scheduled-status-transition', 'POST');
                $this->withInput($request, [
                    'tanggal_efektif' => $tanggal,
                    'alasan' => (string) ($locked->keterangan ?? ''),
                    'status_note' => (string) ($locked->status_note ?? ''),
                ]);

                // Primitive yang sama menjaga lock/re-check, histori, snapshot, dan
                // audit. Actor context dibaca dari snapshot, bukan global Auth/live RBAC.
                $result = $this->lifecycle->mutate(
                    $employee,
                    $targetStatus,
                    $tanggal,
                    (string) ($locked->keterangan ?? ''),
                    $request,
                    $locked->status_note,
                    replaceDocumentSnapshot: in_array($locked->kind, [
                        EmployeeStatusTransition::KIND_STATUS,
                        EmployeeStatusTransition::KIND_EWS_RETIREMENT,
                    ], true),
                    documentFactory: $document === null
                        ? null
                        : static fn (Employee $lockedEmployee, RefStatusPegawai $lockedTargetStatus): array => [
                            'file_path' => $document->file_path,
                            'document_number' => $document->nomor_dokumen,
                        ],
                    intent: $intent,
                    actorContext: $actorContext,
                );

                $locked->update([
                    'is_applied' => true,
                    'applied_at' => now(),
                ]);

                return true;
            });

            if ($needsEwsNotificationRecovery) {
                try {
                    $this->deliverEwsRetirementNotificationOnce($transition);
                } catch (\Throwable $exception) {
                    $this->logEwsNotificationFailure($transition, $exception);
                }

                return false;
            }

            if (! $changed || ! $result instanceof EmployeeStatusMutationResult || ! $result->changed) {
                return false;
            }

            if ($transition->kind === EmployeeStatusTransition::KIND_EWS_RETIREMENT) {
                try {
                    $this->deliverEwsRetirementNotificationOnce($transition, $result);
                } catch (\Throwable $exception) {
                    // Mutation sudah commit; kegagalan intent meninggalkan marker null
                    // agar run berikutnya hanya memulihkan notifikasi lifecycle.
                    $this->logEwsNotificationFailure($transition, $exception);
                }
            } else {
                $this->lifecycle->notify($result, $context);
            }

            return true;
        } catch (\Throwable $e) {
            // Exception storage dapat membawa lokasi file privat; log scheduler hanya
            // menyimpan identifier domain dan tipe error untuk diagnosis aman.
            Log::error('Transisi status terjadwal gagal diterapkan', [
                'transition_id' => $transition->id,
                'employee_id' => $transition->employee_id,
                'error_type' => $e::class,
            ]);

            return false;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function withInput(Request $request, array $data): Request
    {
        $request->replace($data);

        return $request;
    }

    /** Dokumen terjadwal wajib merupakan SK status privat milik pegawai yang sama. */
    private function assertValidDocument(Employee $employee, string $kind, ?Document $document): void
    {
        if ($document === null) {
            return;
        }

        if (! in_array($kind, [
            EmployeeStatusTransition::KIND_STATUS,
            EmployeeStatusTransition::KIND_EWS_RETIREMENT,
        ], true)) {
            throw ValidationException::withMessages([
                'document_id' => 'Dokumen hanya dapat dilampirkan pada perubahan status generik.',
            ]);
        }

        if (! $document->exists) {
            throw ValidationException::withMessages([
                'document_id' => 'Dokumen status harus sudah tersimpan sebelum dijadwalkan.',
            ]);
        }

        if ($document->employee_id !== $employee->id) {
            throw ValidationException::withMessages([
                'document_id' => 'Dokumen status harus dimiliki oleh pegawai yang dijadwalkan.',
            ]);
        }

        $expectedCategory = $kind === EmployeeStatusTransition::KIND_EWS_RETIREMENT
            ? 'sk_pensiun'
            : 'sk_status_pegawai';
        if ($document->jenis_dokumen !== $expectedCategory) {
            throw ValidationException::withMessages([
                'document_id' => 'Kategori dokumen terjadwal tidak sesuai dengan jenis transisi.',
            ]);
        }

        if (! $document->fileExists()) {
            throw ValidationException::withMessages([
                'document_id' => 'File privat dokumen status tidak tersedia.',
            ]);
        }
    }

    private function duplicateScheduleException(): ValidationException
    {
        return ValidationException::withMessages([
            'tanggal_efektif' => 'Perubahan status pada tanggal tersebut sudah dijadwalkan.',
        ]);
    }

    /** Menurunkan intent lifecycle yang sama untuk pembuatan dan eksekusi jadwal. */
    private function intentForKind(string $kind): string
    {
        return match ($kind) {
            EmployeeStatusTransition::KIND_DEACTIVATE => EmployeeStatusLifecycleService::INTENT_DEACTIVATE,
            EmployeeStatusTransition::KIND_RESTORE => EmployeeStatusLifecycleService::INTENT_RESTORE,
            EmployeeStatusTransition::KIND_STATUS => EmployeeStatusLifecycleService::INTENT_GENERIC,
            EmployeeStatusTransition::KIND_EWS_RETIREMENT => EmployeeStatusLifecycleService::INTENT_EWS_RETIREMENT,
            default => throw new RuntimeException("Transisi status jenis '{$kind}' tidak dikenal."),
        };
    }

    /** EWS pensiun membekukan permission deactivate meski kind menyimpan konteks intent. */
    private function authorizationActionForKind(string $kind): string
    {
        return $kind === EmployeeStatusTransition::KIND_EWS_RETIREMENT
            ? EmployeeStatusTransition::KIND_DEACTIVATE
            : $kind;
    }

    /** Source wajib alert PENSIUN aktif milik pegawai yang sama dan hanya untuk kind EWS. */
    private function lockAndValidateEwsSource(
        Employee $employee,
        string $kind,
        ?EwsAlert $sourceEwsAlert,
    ): ?EwsAlert {
        if ($kind !== EmployeeStatusTransition::KIND_EWS_RETIREMENT) {
            if ($sourceEwsAlert !== null) {
                throw ValidationException::withMessages([
                    'source_ews_alert_id' => 'Source EWS hanya boleh digunakan untuk jadwal pensiun EWS.',
                ]);
            }

            return null;
        }

        if ($sourceEwsAlert === null) {
            throw ValidationException::withMessages([
                'source_ews_alert_id' => 'Source alert EWS wajib disertakan untuk pensiun terjadwal.',
            ]);
        }

        $lockedSource = EwsAlert::query()->whereKey($sourceEwsAlert->id)->lockForUpdate()->firstOrFail();
        if ($lockedSource->employee_id !== $employee->id
            || $lockedSource->type !== 'PENSIUN'
            || $lockedSource->followup_status !== EwsAlert::FOLLOWUP_STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'source_ews_alert_id' => 'Source alert EWS tidak cocok atau sudah tidak aktif.',
            ]);
        }

        return $lockedSource;
    }

    /**
     * Intent dan marker lifecycle dibuat dalam satu transaksi sesudah mutation commit.
     * Retry row applied hanya mengulang bagian ini, bukan histori/snapshot/audit/dokumen.
     */
    private function deliverEwsRetirementNotificationOnce(
        EmployeeStatusTransition $transition,
        ?EmployeeStatusMutationResult $result = null,
    ): void {
        DB::transaction(function () use ($transition, $result): void {
            $lockedTransition = EmployeeStatusTransition::query()
                ->whereKey($transition->id)
                ->lockForUpdate()
                ->firstOrFail();
            $source = EwsAlert::query()
                ->whereKey($lockedTransition->source_ews_alert_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($source->lifecycle_notified_at !== null
                || $source->lifecycle_notification_superseded_at !== null) {
                return;
            }
            if ($source->followup_group_id !== $source->id) {
                throw new RuntimeException('Source transisi bukan owner grup follow-up EWS.');
            }

            $employee = Employee::query()
                ->whereKey($lockedTransition->employee_id)
                ->lockForUpdate()
                ->firstOrFail();
            $targetStatus = RefStatusPegawai::query()
                ->whereKey($lockedTransition->status_pegawai_id)
                ->lockForUpdate()
                ->firstOrFail();
            $latestHistory = EmployeeStatusHistory::query()
                ->where('employee_id', $employee->id)
                ->where('is_latest', true)
                ->first();
            $document = $lockedTransition->document;

            // Recovery lama menjadi obsolete bila lifecycle pegawai sudah bergerak
            // lagi (misalnya dipulihkan). Tutup marker tanpa mengirim pesan nonaktif basi.
            if ($employee->status_pegawai_id !== $targetStatus->id
                || RefStatusPegawai::isActiveGroup($targetStatus->kelompok)
                || $latestHistory?->status_pegawai_id !== $targetStatus->id
                || $latestHistory?->tanggal_efektif?->toDateString() !== $lockedTransition->tanggal_efektif->toDateString()
                || (string) $latestHistory?->keterangan !== (string) $lockedTransition->keterangan
                || (string) $latestHistory?->file_sk !== (string) $document?->file_path) {
                EwsAlert::query()
                    ->where('followup_group_id', $source->id)
                    ->update(['lifecycle_notification_superseded_at' => now()]);

                return;
            }

            $notificationResult = $result;
            if (! $notificationResult instanceof EmployeeStatusMutationResult) {
                $notificationResult = new EmployeeStatusMutationResult(
                    $employee,
                    $targetStatus,
                    true,
                    true,
                    false,
                    (string) ($lockedTransition->keterangan ?? ''),
                    $employee->status_note,
                );
            }

            $this->lifecycle->notifyOrFail(
                $notificationResult,
                EmployeeStatusLifecycleService::CONTEXT_EWS_RETIREMENT,
            );

            $now = now();
            EwsAlert::query()
                ->where('followup_group_id', $source->id)
                ->update(['lifecycle_notified_at' => $now]);
        });
    }

    private function logEwsNotificationFailure(
        EmployeeStatusTransition $transition,
        \Throwable $exception,
    ): void {
        Log::error('Intent lifecycle pensiun EWS terjadwal gagal diterbitkan', [
            'transition_id' => $transition->id,
            'employee_id' => $transition->employee_id,
            'error_type' => $exception::class,
        ]);
    }
}
