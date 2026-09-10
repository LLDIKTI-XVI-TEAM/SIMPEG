<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStatusTransition;
use App\Models\RefStatusPegawai;
use App\Services\AuditService;
use App\Services\NotificationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Primitive tunggal untuk snapshot, histori, audit, otorisasi, dan intent lifecycle. */
class EmployeeStatusLifecycleService
{
    public const DEFAULT_DEACTIVATION_NOTE = 'AKUN ANDA TELAH DI NONAKTIFKAN, SILAHKAN HUBUNGI ADMIN!!';

    public const CONTEXT_GENERIC = 'generic';

    public const CONTEXT_DEACTIVATE = 'deactivate';

    public const CONTEXT_RESTORE = 'restore';

    public const CONTEXT_EWS_RETIREMENT = 'ews_retirement';

    public const INTENT_GENERIC = 'generic';

    public const INTENT_DEACTIVATE = 'deactivate';

    public const INTENT_RESTORE = 'restore';

    public const INTENT_EWS_RETIREMENT = 'ews_retirement';

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Mengubah status setelah lock dan re-check; factory dokumen baru dipanggil setelah
     * no-op ditolak agar retry tidak membuat file atau metadata yatim.
     *
     * @param  (Closure(Employee, RefStatusPegawai): array{file_path: ?string, document_number: ?string})|null  $documentFactory
     */
    public function mutate(
        Employee $employee,
        RefStatusPegawai $targetStatus,
        string $effectiveDate,
        string $reason,
        Request $request,
        ?string $statusNote = null,
        bool $replaceDocumentSnapshot = false,
        ?Closure $documentFactory = null,
        string $intent = self::INTENT_GENERIC,
        ?EmployeeStatusActorContext $actorContext = null,
    ): EmployeeStatusMutationResult {
        return DB::transaction(function () use ($employee, $targetStatus, $effectiveDate, $reason, $request, $statusNote, $replaceDocumentSnapshot, $documentFactory, $intent, $actorContext): EmployeeStatusMutationResult {
            $lockedEmployee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            // Urutan lock lifecycle selalu pegawai lalu target status. Ini menyerialkan
            // klasifikasi control-plane sebelum permission, no-op, dan histori dinilai.
            $lockedTargetStatus = $this->lockActiveTargetStatus($targetStatus);
            $wasActive = $lockedEmployee->isActive();
            $isActive = RefStatusPegawai::isActiveGroup($lockedTargetStatus->kelompok);

            $shouldMutate = $this->shouldMutateLocked($lockedEmployee, $lockedTargetStatus, $intent);
            $resolvedActorContext = $actorContext;
            if (! $resolvedActorContext instanceof EmployeeStatusActorContext
                && app()->environment('local')
                && config('services.simpeg.disable_employee_api_auth')) {
                $resolvedActorContext = EmployeeStatusActorContext::capture(
                    $request,
                    $this->authorizationPermission($lockedEmployee, $lockedTargetStatus, $intent),
                    $this->authorizationAction($intent),
                );
            }
            $this->assertTransitionAllowed($lockedEmployee, $lockedTargetStatus, $request, $intent, $resolvedActorContext);

            // Target status yang sama selalu no-op; perubahan tanggal/alasan tidak boleh
            // menyamarkan retry sebagai histori lifecycle baru.
            if (! $shouldMutate) {
                return new EmployeeStatusMutationResult(
                    $lockedEmployee,
                    $lockedTargetStatus,
                    false,
                    $wasActive,
                    $isActive,
                    $reason,
                    $lockedEmployee->status_note,
                );
            }

            $oldValues = $lockedEmployee->getRawOriginal();
            $resolvedNote = $this->resolveStatusNote($lockedEmployee, $wasActive, $isActive, $statusNote);
            $document = $documentFactory?->__invoke($lockedEmployee, $lockedTargetStatus) ?? [
                'file_path' => null,
                'document_number' => null,
            ];

            EmployeeStatusHistory::query()
                ->where('employee_id', $lockedEmployee->id)
                ->where('is_latest', true)
                ->update(['is_latest' => false]);

            EmployeeStatusHistory::create([
                'employee_id' => $lockedEmployee->id,
                'status_pegawai_id' => $lockedTargetStatus->id,
                'status_nama' => $lockedTargetStatus->nama,
                'keterangan' => $reason,
                'tanggal_efektif' => $effectiveDate,
                'nomor_berkas' => $document['document_number'],
                'file_sk' => $document['file_path'],
                // FK boleh null bila aktor dihapus; identitas immutable tetap berada pada
                // transition provenance dan audit_logs.user_id yang tidak memakai FK user.
                'changed_by_user_id' => $resolvedActorContext instanceof EmployeeStatusActorContext
                    ? $resolvedActorContext->linkedUserId
                    : auth()->id(),
                'is_latest' => true,
            ]);

            $snapshot = [
                'status_pegawai_id' => $lockedTargetStatus->id,
                'status_aktif' => $lockedTargetStatus->nama,
                'status_keterangan' => $reason,
                'status_note' => $resolvedNote,
                'status_tanggal' => $effectiveDate,
            ];

            if ($replaceDocumentSnapshot) {
                $snapshot += [
                    'status_berkas_path' => $document['file_path'],
                    'status_nomor_berkas' => $document['document_number'],
                ];
            }

            $lockedEmployee->forceFill($snapshot)->saveOrFail();
            $lockedEmployee->refresh();

            // Audit lifecycle adalah bagian transaksi dan hanya membawa field status;
            // kegagalan audit wajib me-roll back snapshot serta histori.
            if ($resolvedActorContext instanceof EmployeeStatusActorContext) {
                AuditService::logAsOrFail(
                    $resolvedActorContext->actorUserId,
                    $resolvedActorContext->actorName,
                    'UPDATE',
                    'Employee',
                    $lockedEmployee->id,
                    AuditService::statusPayload($oldValues),
                    AuditService::statusPayload($lockedEmployee->getRawOriginal()),
                    ipAddress: $resolvedActorContext->ipAddress,
                    userAgent: $resolvedActorContext->userAgent,
                    simulationContext: $resolvedActorContext->auditContext(),
                );
            } else {
                AuditService::logOrFail(
                    'UPDATE',
                    'Employee',
                    $lockedEmployee->id,
                    AuditService::statusPayload($oldValues),
                    AuditService::statusPayload($lockedEmployee->getRawOriginal()),
                    $request,
                );
            }

            return new EmployeeStatusMutationResult(
                $lockedEmployee,
                $lockedTargetStatus,
                true,
                $wasActive,
                $isActive,
                $reason,
                $resolvedNote,
            );
        });
    }

    /**
     * Memuat ulang target di bawah row lock agar status yang dihapus atau dinonaktifkan
     * control-plane gagal sebagai validasi domain, bukan error FK atau snapshot usang.
     */
    public function lockActiveTargetStatus(RefStatusPegawai|string $targetStatus): RefStatusPegawai
    {
        $targetStatusId = $targetStatus instanceof RefStatusPegawai
            ? $targetStatus->id
            : $targetStatus;
        $lockedTargetStatus = RefStatusPegawai::query()
            ->whereKey($targetStatusId)
            ->lockForUpdate()
            ->first();

        if ($lockedTargetStatus === null || ! $lockedTargetStatus->is_active) {
            throw ValidationException::withMessages([
                'status_pegawai_id' => 'Status tujuan tidak tersedia atau sudah dinonaktifkan.',
            ]);
        }

        return $lockedTargetStatus;
    }

    /** Menegakkan permission lifecycle dari role efektif, termasuk caller non-HTTP. */
    public function assertTransitionAllowed(
        Employee $employee,
        RefStatusPegawai $targetStatus,
        Request $request,
        string $intent,
        ?EmployeeStatusActorContext $actorContext = null,
    ): void {
        $requiredPermission = $this->authorizationPermission($employee, $targetStatus, $intent);
        $requiredAction = $this->authorizationAction($intent);

        // Scheduler hanya boleh memakai provenance immutable yang persis sama dengan
        // permission dan action transisi ini, tanpa membaca RBAC live yang dapat berubah.
        if ($actorContext instanceof EmployeeStatusActorContext) {
            $actorContext->assertAuthorizes($requiredPermission, $requiredAction);

            return;
        }

        $localBypass = app()->environment('local')
            && config('services.simpeg.disable_employee_api_auth');

        // Flag ini memang menghapus gate route/FormRequest untuk pengujian API lokal.
        // Evaluasi sebelum aktor agar kontrak tersebut tidak rusak, tetapi tetap
        // mustahil aktif di environment selain local.
        if ($localBypass) {
            return;
        }

        $user = $request->user() ?? auth()->user();

        if ($user === null) {
            throw ValidationException::withMessages([
                'actor' => 'Aktor perubahan status tidak dapat diverifikasi.',
            ]);
        }

        if ($user->hasPermission($requiredPermission)) {
            return;
        }

        $message = match ($requiredPermission) {
            'employees.restore' => 'Mengaktifkan kembali pegawai nonaktif memerlukan permission employees.restore.',
            'employees.deactivate' => 'Menonaktifkan pegawai memerlukan permission employees.deactivate.',
            default => 'Mengubah status pegawai memerlukan permission employees.update.',
        };

        throw ValidationException::withMessages([
            'status_pegawai_id' => $message,
        ]);
    }

    /** Menyamakan action provenance untuk jalur langsung dan terjadwal. */
    private function authorizationAction(string $intent): string
    {
        return match ($intent) {
            self::INTENT_DEACTIVATE, self::INTENT_EWS_RETIREMENT => EmployeeStatusTransition::KIND_DEACTIVATE,
            self::INTENT_RESTORE => EmployeeStatusTransition::KIND_RESTORE,
            default => EmployeeStatusTransition::KIND_STATUS,
        };
    }

    /** Menentukan permission lifecycle yang dibuktikan dan dibekukan saat schedule. */
    public function authorizationPermission(
        Employee $employee,
        RefStatusPegawai $targetStatus,
        string $intent,
    ): string {
        if ($intent === self::INTENT_RESTORE) {
            return 'employees.restore';
        }

        if (in_array($intent, [self::INTENT_DEACTIVATE, self::INTENT_EWS_RETIREMENT], true)) {
            return 'employees.deactivate';
        }

        $isCurrentlyActive = $employee->isActive();
        $isTargetActive = RefStatusPegawai::isActiveGroup($targetStatus->kelompok);

        if ($isCurrentlyActive && ! $isTargetActive) {
            return 'employees.deactivate';
        }

        if (! $isCurrentlyActive && $isTargetActive) {
            return 'employees.restore';
        }

        return 'employees.update';
    }

    /** Menerbitkan tepat satu intent lifecycle sesudah transaksi pemanggil berhasil. */
    public function notify(EmployeeStatusMutationResult $result, string $context = self::CONTEXT_GENERIC): void
    {
        if (! $result->changed) {
            return;
        }

        try {
            $this->notifyOrFail($result, $context);
        } catch (\Throwable $exception) {
            Log::error('Notification failed after employee status lifecycle', [
                'employee_id' => $result->employee->id,
                'event' => $this->notificationEvent($result),
                'error_type' => $exception::class,
            ]);
        }
    }

    /** Menulis intent notifikasi tanpa menelan error agar marker dedup dapat rollback. */
    public function notifyOrFail(EmployeeStatusMutationResult $result, string $context = self::CONTEXT_GENERIC): void
    {
        if (! $result->changed) {
            return;
        }

        $isDeactivation = $result->wasActive && ! $result->isActive;
        $type = $this->notificationEvent($result);

        if ($isDeactivation) {
            $title = 'Akun Anda Dinonaktifkan';
            $body = $result->statusNote ?? self::DEFAULT_DEACTIVATION_NOTE;
            $url = route('status-akun', [], false);
        } elseif ($context === self::CONTEXT_RESTORE) {
            $title = 'Akun Anda Telah Diaktifkan Kembali';
            $body = 'Status kepegawaian Anda telah diaktifkan kembali. '
                .($result->reason !== '' ? 'Keterangan: '.$result->reason : '');
            $url = route('profil', [], false);
        } else {
            $title = 'Status Kepegawaian Anda Diperbarui';
            $body = 'Status kepegawaian Anda telah diubah menjadi "'.$result->targetStatus->nama.'". '
                .($result->reason !== '' ? 'Keterangan: '.$result->reason : '');
            $url = route('profil', [], false);
        }

        $this->notifications->createForEmployee(
            $result->employee,
            $type,
            $title,
            trim($body),
            [
                'status_pegawai_id' => $result->targetStatus->id,
                'url' => $url,
            ],
        );
    }

    /**
     * Memvalidasi source state dan no-op dari row employee yang sudah dikunci.
     * Helper ini dipakai saat membuat maupun menerapkan jadwal agar keduanya
     * tidak berbeda dalam menilai intent lifecycle.
     */
    public function shouldMutateLocked(Employee $lockedEmployee, RefStatusPegawai $targetStatus, string $intent): bool
    {
        $this->assertIntentPrecondition($lockedEmployee->isActive(), $intent);

        return $lockedEmployee->status_pegawai_id !== $targetStatus->id;
    }

    /** Memastikan intent khusus masih cocok dengan state row yang baru dikunci. */
    private function assertIntentPrecondition(bool $wasActive, string $intent): void
    {
        if (in_array($intent, [self::INTENT_DEACTIVATE, self::INTENT_EWS_RETIREMENT], true) && ! $wasActive) {
            throw ValidationException::withMessages([
                'status_pegawai_id' => 'Penonaktifan hanya dapat dilakukan pada pegawai yang masih aktif.',
            ]);
        }

        if ($intent === self::INTENT_RESTORE && $wasActive) {
            throw ValidationException::withMessages([
                'status_pegawai_id' => 'Reaktivasi hanya dapat dilakukan pada pegawai yang masih nonaktif.',
            ]);
        }

        if (! in_array($intent, [
            self::INTENT_GENERIC,
            self::INTENT_DEACTIVATE,
            self::INTENT_RESTORE,
            self::INTENT_EWS_RETIREMENT,
        ], true)) {
            throw new \InvalidArgumentException("Intent lifecycle '{$intent}' tidak dikenal.");
        }
    }

    private function resolveStatusNote(
        Employee $employee,
        bool $wasActive,
        bool $isActive,
        ?string $statusNote,
    ): ?string {
        if (! $wasActive && $isActive) {
            return null;
        }

        if ($wasActive && ! $isActive) {
            $normalized = trim((string) $statusNote);

            return $normalized !== '' ? $normalized : self::DEFAULT_DEACTIVATION_NOTE;
        }

        return $statusNote === null ? $employee->status_note : trim($statusNote);
    }

    /** Event dicatat sebagai identifier domain; body/alasan tidak boleh masuk log kegagalan. */
    private function notificationEvent(EmployeeStatusMutationResult $result): string
    {
        return $result->wasActive && ! $result->isActive
            ? 'status_pegawai.dinonaktifkan'
            : 'status_pegawai.diubah';
    }
}
