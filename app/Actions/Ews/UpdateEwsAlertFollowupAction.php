<?php

namespace App\Actions\Ews;

use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStatusTransition;
use App\Models\EwsAlert;
use App\Models\RefStatusPegawai;
use App\Models\SimpegNotification;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use App\Services\EmployeeHistoryService;
use App\Services\Employees\EmployeeStatusLifecycleService;
use App\Services\Employees\EmployeeStatusMutationResult;
use App\Services\Employees\EmployeeStatusTransitionService;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateEwsAlertFollowupAction
{
    public function __construct(
        private readonly EmployeeHistoryService $histories,
        private readonly EmployeeFileStorageService $files,
        private readonly NotificationService $notifications,
        private readonly EmployeeStatusLifecycleService $lifecycle,
        private readonly EmployeeStatusTransitionService $transitions,
    ) {}

    /**
     * Menangani alert EWS. Pangkat/KGB membuat riwayat baru; Pensiun efektif kini
     * langsung memakai lifecycle, sedangkan tanggal future hanya membuat jadwal.
     */
    public function execute(EwsAlert $alert, string $followupStatus, string $handledNote, Request $request): EwsAlert
    {
        $before = [];
        $employee = null;
        $storedSkPaths = [];
        $scheduledSkPath = null;
        $scheduledSkRecoveryTaskId = null;
        $scheduledSkEmployeeId = null;
        $domainChanged = false;
        $lifecycleDeferred = false;

        try {
            $alert = DB::transaction(function () use ($alert, $followupStatus, $handledNote, $request, &$before, &$employee, &$storedSkPaths, &$scheduledSkPath, &$scheduledSkRecoveryTaskId, &$scheduledSkEmployeeId, &$domainChanged, &$lifecycleDeferred): EwsAlert {
                $initialAlert = $alert;
                $lockedAlerts = $this->lockRelevantAlertSet($initialAlert);
                $alert = $lockedAlerts->firstWhere('id', $initialAlert->id);
                if (! $alert instanceof EwsAlert) {
                    throw ValidationException::withMessages([
                        'followup_status' => 'Alert EWS berubah sebelum tindak lanjut dapat dikunci.',
                    ]);
                }

                $this->assertSelectedAlertStable($initialAlert, $alert);
                $prelockedAlertIds = $lockedAlerts
                    ->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE)
                    ->modelKeys();

                if ($alert->followup_status !== EwsAlert::FOLLOWUP_STATUS_ACTIVE) {
                    if (! $this->canRecoverNotificationIntents($alert, $followupStatus)) {
                        throw ValidationException::withMessages([
                            'followup_status' => 'Alert EWS hanya dapat ditindaklanjuti saat status masih aktif.',
                        ]);
                    }

                    $employee = Employee::query()->lockForUpdate()->findOrFail($alert->employee_id);

                    return $alert;
                }

                $domainChanged = true;
                $before = $this->alertSnapshot($alert);
                $employee = Employee::query()->lockForUpdate()->findOrFail($alert->employee_id);

                if ($followupStatus === EwsAlert::FOLLOWUP_STATUS_HANDLED) {
                    if (in_array($alert->type, ['KENAIKAN_PANGKAT', 'KGB'], true)) {
                        $storedSkPaths[] = $this->createApprovedHistory($employee, $alert->type, $request);
                    } elseif ($alert->type === 'PENSIUN') {
                        $lifecycleDeferred = $this->approveRetirement(
                            $alert,
                            $employee,
                            $request,
                            $storedSkPaths,
                            $scheduledSkPath,
                            $scheduledSkRecoveryTaskId,
                            $scheduledSkEmployeeId,
                        );
                    }

                    // Semua tipe (termasuk KONTRAK_PPPK dan SATYALANCANA) harus menutup
                    // alert satu tipe beserta notifikasinya. Untuk tipe yang target
                    // datanya tidak berubah setelah ditangani, notifikasi yang masih
                    // unread akan membuat scheduler menghidupkan kembali pengingat dan
                    // menghapus acknowledgement pada run berikutnya.
                    $this->resolveCurrentTypeAlerts(
                        $employee,
                        $alert,
                        $followupStatus,
                        $handledNote,
                        $request,
                        $prelockedAlertIds,
                    );
                } elseif ($followupStatus === EwsAlert::FOLLOWUP_STATUS_NOT_NEEDED) {
                    // Tidak mengubah riwayat maupun status pegawai; hanya menutup EWS terkait.
                    $this->resolveCurrentTypeAlerts(
                        $employee,
                        $alert,
                        $followupStatus,
                        $handledNote,
                        $request,
                        $prelockedAlertIds,
                    );
                }

                return $alert;
            });
        } catch (\Throwable $exception) {
            // File SK diunggah ke storage di dalam alur transaksi; rollback database
            // tidak menghapusnya, sehingga kompensasi manual diperlukan agar tidak
            // ada orphan file tanpa riwayat/dokumen yang merujuknya.
            foreach (array_filter($storedSkPaths) as $storedSkPath) {
                $this->files->deleteEmployeeDocumentFile($storedSkPath);
            }
            if (is_string($scheduledSkEmployeeId)) {
                $this->files->deleteEmployeeStatusDocument($scheduledSkPath, $scheduledSkEmployeeId);
            }

            throw $exception;
        }

        if (is_string($scheduledSkRecoveryTaskId)
            && is_string($scheduledSkEmployeeId)
            && is_string($scheduledSkPath)) {
            $this->files->adoptEmployeeStatusDocument(
                $scheduledSkRecoveryTaskId,
                $scheduledSkEmployeeId,
                $scheduledSkPath,
            );
        }

        $alert->refresh();
        if ($domainChanged) {
            AuditService::log('UPDATE', 'EwsAlert', $alert->id, $before, $this->alertSnapshot($alert), $request);
        }

        if ($employee instanceof Employee) {
            if (! $lifecycleDeferred) {
                $this->deliverLifecycleNotificationOnce($alert, $employee);
            }
            $this->deliverFollowupNotificationOnce($alert, $employee);
        }

        return $alert;
    }

    /**
     * Membuat riwayat pangkat/KGB dan mengembalikan path file SK yang tersimpan
     * agar pemanggil dapat menghapusnya bila transaksi luar akhirnya rollback.
     */
    private function createApprovedHistory(Employee $employee, string $type, Request $request): ?string
    {
        if ($type === 'KENAIKAN_PANGKAT') {
            $tmt = (string) $request->input('tmt_pangkat');
            $this->ensureNewerTmt($employee, 'rankHistories', 'tmt_pangkat', $tmt, 'tmt_pangkat');
            $history = $this->histories->createRankHistory($employee, [
                'golongan_id' => (string) $request->input('golongan_id'),
                'tmt_pangkat' => $tmt,
                'no_sk' => (string) $request->input('no_sk'),
                'tanggal_sk' => (string) $request->input('tanggal_sk'),
                'file_sk' => $request->file('file_sk'),
            ], $request);

            return $history->file_sk;
        }

        $tmt = (string) $request->input('tmt_kgb');
        $this->ensureNewerTmt($employee, 'salaryHistories', 'tmt_kgb', $tmt, 'tmt_kgb');
        $history = $this->histories->createKgbHistory($employee, [
            'tmt_kgb' => $tmt,
            'gaji_pokok' => $request->input('gaji_pokok'),
            'no_sk' => (string) $request->input('no_sk'),
            'tanggal_sk' => (string) $request->input('tanggal_sk'),
            'file_sk' => $request->file('file_sk'),
        ], $request);

        return $history->file_sk;
    }

    /**
     * Menyetujui pensiun langsung atau menjadwalkannya. Kedua jalur mencatat path
     * segera setelah storage berhasil agar rollback tetap dapat dikompensasi.
     *
     * @param  array<int, string|null>  $storedSkPaths
     */
    private function approveRetirement(
        EwsAlert $sourceAlert,
        Employee $employee,
        Request $request,
        array &$storedSkPaths,
        ?string &$scheduledSkPath,
        ?string &$scheduledSkRecoveryTaskId,
        ?string &$scheduledSkEmployeeId,
    ): bool {
        $pensionStatus = RefStatusPegawai::query()->where('kode', 'PENSIUN')->firstOrFail();
        $reason = 'Status diubah menjadi Pensiun melalui persetujuan EWS.';

        if ($this->transitions->isFuture((string) $request->input('tanggal_sk'))) {
            $this->transitions->scheduleWithDocumentFactory(
                $employee,
                $pensionStatus,
                (string) $request->input('tanggal_sk'),
                EmployeeStatusTransition::KIND_EWS_RETIREMENT,
                $reason,
                null,
                function (Employee $lockedEmployee) use ($request, &$scheduledSkPath, &$scheduledSkRecoveryTaskId, &$scheduledSkEmployeeId): Document {
                    $stored = $this->files->storeEmployeeStatusDocument(
                        $request->file('file_sk'),
                        $lockedEmployee->id,
                    );
                    $scheduledSkPath = $stored['path'];
                    $scheduledSkRecoveryTaskId = $stored['recovery_task_id'];
                    $scheduledSkEmployeeId = $lockedEmployee->id;

                    return $lockedEmployee->documents()->create([
                        'jenis_dokumen' => 'sk_pensiun',
                        'nama_dokumen' => 'SK Pensiun',
                        'nomor_dokumen' => (string) $request->input('no_sk'),
                        'tanggal_dokumen' => (string) $request->input('tanggal_sk'),
                        'file_path' => $scheduledSkPath,
                        'keterangan' => 'Diunggah saat persetujuan EWS Pensiun.',
                    ]);
                },
                $request,
                $sourceAlert,
            );

            return true;
        }

        $result = $this->lifecycle->mutate(
            $employee,
            $pensionStatus,
            (string) $request->input('tanggal_sk'),
            $reason,
            $request,
            replaceDocumentSnapshot: true,
            documentFactory: function (Employee $lockedEmployee, RefStatusPegawai $lockedStatus) use ($request, &$storedSkPaths): array {
                $filePath = $this->files->storeSk($request->file('file_sk'));
                $storedSkPaths[] = $filePath;

                $lockedEmployee->documents()->create([
                    'jenis_dokumen' => 'sk_pensiun',
                    'nama_dokumen' => 'SK Pensiun',
                    'nomor_dokumen' => (string) $request->input('no_sk'),
                    'tanggal_dokumen' => (string) $request->input('tanggal_sk'),
                    'file_path' => $filePath,
                    'keterangan' => 'Diunggah saat persetujuan EWS Pensiun.',
                ]);

                return [
                    'file_path' => $filePath,
                    'document_number' => (string) $request->input('no_sk'),
                ];
            },
            intent: EmployeeStatusLifecycleService::INTENT_EWS_RETIREMENT,
        );

        $statusHistoryId = EmployeeStatusHistory::query()
            ->where('employee_id', $employee->id)
            ->where('is_latest', true)
            ->value('id');
        if (! $result->changed || ! is_string($statusHistoryId)) {
            throw new \RuntimeException('Lineage histori status pensiun EWS tidak dapat diverifikasi.');
        }

        // Owner alert menyimpan generasi histori yang melahirkan intent. Retry lama
        // tidak boleh menempel ke pensiun baru yang kebetulan memiliki payload sama.
        $sourceAlert->forceFill(['lifecycle_status_history_id' => $statusHistoryId])->saveOrFail();

        return false;
    }

    /** Retry alert tertutup hanya boleh memulihkan marker intent yang belum lengkap. */
    private function canRecoverNotificationIntents(EwsAlert $alert, string $followupStatus): bool
    {
        if ($alert->followup_status !== $followupStatus) {
            return false;
        }

        // Hanya alert owner yang boleh memulihkan intent. Sibling membawa group ID
        // owner yang sama tetapi tidak pernah menjadi sumber notifikasi sendiri.
        if ($alert->followup_group_id !== $alert->id) {
            return false;
        }

        if ($alert->followup_notified_at === null) {
            return true;
        }

        if ($alert->type !== 'PENSIUN'
            || $followupStatus !== EwsAlert::FOLLOWUP_STATUS_HANDLED
            || $alert->lifecycle_notified_at !== null
            || $alert->lifecycle_notification_superseded_at !== null) {
            return false;
        }

        $sourceTransition = EmployeeStatusTransition::query()
            ->where('kind', EmployeeStatusTransition::KIND_EWS_RETIREMENT)
            ->where('source_ews_alert_id', $alert->id)
            ->first();

        return $sourceTransition?->is_applied ?? false;
    }

    /** Marker dan intent lifecycle commit bersama agar recovery tidak menggandakan event. */
    private function deliverLifecycleNotificationOnce(EwsAlert $alert, Employee $employee): void
    {
        if ($alert->type !== 'PENSIUN' || $alert->followup_status !== EwsAlert::FOLLOWUP_STATUS_HANDLED) {
            return;
        }

        if (EmployeeStatusTransition::query()
            ->where('kind', EmployeeStatusTransition::KIND_EWS_RETIREMENT)
            ->where('source_ews_alert_id', $alert->id)
            ->where('is_applied', false)
            ->exists()) {
            return;
        }

        DB::transaction(function () use ($alert, $employee): void {
            $lockedAlert = EwsAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
            if ($lockedAlert->lifecycle_notified_at !== null
                || $lockedAlert->lifecycle_notification_superseded_at !== null) {
                return;
            }
            if ($lockedAlert->followup_group_id !== $lockedAlert->id) {
                throw new \RuntimeException('Owner grup follow-up EWS tidak valid untuk recovery lifecycle.');
            }

            $persistedEmployee = Employee::query()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();
            $pensionStatus = RefStatusPegawai::query()
                ->where('kode', 'PENSIUN')
                ->lockForUpdate()
                ->firstOrFail();
            $latestHistory = EmployeeStatusHistory::query()
                ->where('employee_id', $persistedEmployee->id)
                ->where('is_latest', true)
                ->first();
            $expectedReason = 'Status diubah menjadi Pensiun melalui persetujuan EWS.';
            $sourceTransition = EmployeeStatusTransition::query()
                ->where('kind', EmployeeStatusTransition::KIND_EWS_RETIREMENT)
                ->where('source_ews_alert_id', $lockedAlert->id)
                ->first();
            $generationMatches = $sourceTransition instanceof EmployeeStatusTransition
                ? $latestHistory?->status_pegawai_id === $sourceTransition->status_pegawai_id
                    && $latestHistory?->tanggal_efektif?->toDateString() === $sourceTransition->tanggal_efektif->toDateString()
                    && (string) $latestHistory?->keterangan === (string) $sourceTransition->keterangan
                    && (string) $latestHistory?->file_sk === (string) $sourceTransition->document?->file_path
                : is_string($lockedAlert->lifecycle_status_history_id)
                    && $latestHistory?->id === $lockedAlert->lifecycle_status_history_id;
            $notificationReason = $sourceTransition instanceof EmployeeStatusTransition
                ? (string) $sourceTransition->keterangan
                : $expectedReason;

            // Intent lama tidak boleh membalik urutan informasi setelah pegawai
            // dipulihkan atau berpindah status sebelum retry notifikasi dilakukan.
            if ($persistedEmployee->status_pegawai_id !== $pensionStatus->id
                || RefStatusPegawai::isActiveGroup($pensionStatus->kelompok)
                || ! $generationMatches) {
                EwsAlert::query()
                    ->where('followup_group_id', $lockedAlert->id)
                    ->update(['lifecycle_notification_superseded_at' => now()]);

                return;
            }

            $result = new EmployeeStatusMutationResult(
                $persistedEmployee,
                $pensionStatus,
                true,
                true,
                false,
                $notificationReason,
                $persistedEmployee->status_note,
            );

            $this->lifecycle->notifyOrFail($result, EmployeeStatusLifecycleService::CONTEXT_EWS_RETIREMENT);
            EwsAlert::query()
                ->where('followup_group_id', $lockedAlert->id)
                ->update(['lifecycle_notified_at' => now()]);
        });
    }

    /** Marker dan intent follow-up commit bersama mengikuti pola completion_notified_at. */
    private function deliverFollowupNotificationOnce(EwsAlert $alert, Employee $employee): void
    {
        DB::transaction(function () use ($alert, $employee): void {
            $lockedAlert = EwsAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
            if ($lockedAlert->followup_notified_at !== null) {
                return;
            }

            $typeLabel = EwsAlert::typeLabels()[$lockedAlert->type] ?? $lockedAlert->type;
            if ($lockedAlert->followup_status === EwsAlert::FOLLOWUP_STATUS_HANDLED) {
                $notificationType = match ($lockedAlert->type) {
                    'KENAIKAN_PANGKAT' => 'ews.followup.kenaikan_pangkat',
                    'KGB' => 'ews.followup.kgb',
                    'PENSIUN' => 'ews.followup.pensiun',
                    'KONTRAK_PPPK' => 'ews.followup.kontrak_pppk',
                    'SATYALANCANA' => 'ews.followup.satyalancana',
                    default => 'ews.followup.'.strtolower($lockedAlert->type),
                };
                $title = 'Tindak Lanjut EWS: Ditangani';
                $body = trim((string) $lockedAlert->handled_note) !== ''
                    ? (string) $lockedAlert->handled_note
                    : "Tindak lanjut EWS {$typeLabel} Anda telah ditangani oleh petugas kepegawaian.";
            } else {
                $notificationType = 'ews.followup.tidak_perlu';
                $title = 'Tindak Lanjut EWS: Tidak Perlu';
                $body = trim((string) $lockedAlert->handled_note) !== ''
                    ? (string) $lockedAlert->handled_note
                    : "Tindak lanjut EWS {$typeLabel} Anda telah ditandai tidak perlu oleh Admin.";
            }

            $this->notifications->createForEmployee(
                $employee,
                $notificationType,
                $title,
                $body,
                [
                    'ews_alert_id' => $lockedAlert->id,
                    'followup_status' => $lockedAlert->followup_status,
                    'event_type' => $lockedAlert->type,
                    'handled_note' => $lockedAlert->handled_note,
                    'url' => route('ews.saya', [], false),
                ],
            );
            $lockedAlert->forceFill(['followup_notified_at' => now()])->saveOrFail();
        });
    }

    private function ensureNewerTmt(Employee $employee, string $relation, string $column, string $tmt, string $attribute): void
    {
        $latestTmt = $employee->{$relation}()->max($column);
        if ($latestTmt !== null && Carbon::parse($tmt)->lessThanOrEqualTo(Carbon::parse($latestTmt))) {
            throw ValidationException::withMessages([
                $attribute => 'TMT baru harus lebih besar dari TMT riwayat terakhir agar target EWS dapat direset.',
            ]);
        }
    }

    /**
     * Mengunci source dan seluruh sibling aktif sebelum lock pegawai.
     * Urutan UUID yang sama mencegah dua follow-up memilih sibling berbeda lalu saling menunggu.
     *
     * @return EloquentCollection<int, EwsAlert>
     */
    private function lockRelevantAlertSet(EwsAlert $initialAlert): EloquentCollection
    {
        return EwsAlert::query()
            ->where(function ($query) use ($initialAlert): void {
                if ($initialAlert->followup_status === EwsAlert::FOLLOWUP_STATUS_ACTIVE) {
                    $query->where(function ($siblings) use ($initialAlert): void {
                        $siblings
                            ->where('employee_id', $initialAlert->employee_id)
                            ->where('type', $initialAlert->type)
                            ->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE);
                    })->orWhere('id', $initialAlert->id);

                    return;
                }

                $query->whereKey($initialAlert->id);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** Route binding tidak boleh diam-diam berubah menjadi source/group lain saat menunggu lock. */
    private function assertSelectedAlertStable(EwsAlert $initialAlert, EwsAlert $lockedAlert): void
    {
        $identityOrOwnershipChanged = $initialAlert->employee_id !== $lockedAlert->employee_id
            || $initialAlert->type !== $lockedAlert->type
            || $initialAlert->target_date?->toDateString() !== $lockedAlert->target_date?->toDateString()
            || $initialAlert->interval_days !== $lockedAlert->interval_days
            || $initialAlert->followup_status !== $lockedAlert->followup_status
            || $initialAlert->followup_group_id !== $lockedAlert->followup_group_id;
        $activeAlertAlreadyHasOwner = $lockedAlert->followup_status === EwsAlert::FOLLOWUP_STATUS_ACTIVE
            && $lockedAlert->followup_group_id !== null;

        if ($identityOrOwnershipChanged || $activeAlertAlreadyHasOwner) {
            throw ValidationException::withMessages([
                'followup_status' => 'Alert EWS berubah selama proses tindak lanjut. Silakan muat ulang data.',
            ]);
        }
    }

    /**
     * @param  array<int, string>  $prelockedAlertIds
     */
    private function resolveCurrentTypeAlerts(
        Employee $employee,
        EwsAlert $selectedAlert,
        string $status,
        string $note,
        Request $request,
        array $prelockedAlertIds,
    ): void {
        // Baris baru yang commit saat HTTP menunggu set awal direkonsiliasi setelah
        // employee terkunci. Untuk PENSIUN creator baru juga membutuhkan lock employee,
        // sehingga baris tambahan ini tidak sedang menunggu balik pada transaksi HTTP.
        $concurrentAlertIds = EwsAlert::query()
            ->where('employee_id', $employee->id)
            ->where('type', $selectedAlert->type)
            ->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE)
            ->whereNotIn('id', $prelockedAlertIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();
        $alertIds = array_values(array_unique([...$prelockedAlertIds, ...$concurrentAlertIds]));
        $now = now();

        EwsAlert::query()->whereKey($alertIds)->update($this->followupAttributes(
            $status,
            $note,
            $request,
            $selectedAlert->id,
            $now,
        ));

        // Hanya alert yang dipilih boleh menjadi owner recovery. Sibling ditutup
        // sebagai satu milestone, tetapi tidak pernah memiliki intent notifikasi sendiri.
        EwsAlert::query()
            ->whereKey($alertIds)
            ->whereKeyNot($selectedAlert->id)
            ->update(['followup_notified_at' => $now]);

        SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('is_read', false)
            ->where(function ($query) use ($alertIds): void {
                $query->whereIn('ews_alert_id', $alertIds);
                foreach ($alertIds as $alertId) {
                    $query->orWhereJsonContains('data->ews_alert_id', $alertId);
                }
            })
            ->update(['is_read' => true, 'read_at' => $now, 'updated_at' => $now]);
    }

    /** @return array<string, mixed> */
    private function followupAttributes(
        string $status,
        string $note,
        Request $request,
        string $followupGroupId,
        mixed $handledAt = null,
    ): array {
        $handledAt ??= now();

        return [
            'followup_status' => $status,
            'handled_at' => $handledAt,
            'handled_by' => $request->user()?->id,
            'handled_note' => $note,
            'followup_group_id' => $followupGroupId,
            'is_processed' => true,
            'notification_acknowledged_at' => $handledAt,
        ];
    }

    /** @return array<string, mixed> */
    private function alertSnapshot(EwsAlert $alert): array
    {
        return [
            'followup_status' => $alert->followup_status,
            'handled_at' => $alert->handled_at?->toDateTimeString(),
            'handled_by' => $alert->handled_by,
            'handled_note' => $alert->handled_note,
        ];
    }
}
