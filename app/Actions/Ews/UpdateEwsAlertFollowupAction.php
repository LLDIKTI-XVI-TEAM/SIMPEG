<?php

namespace App\Actions\Ews;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\RefStatusPegawai;
use App\Models\SimpegNotification;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use App\Services\EmployeeHistoryService;
use App\Services\NotificationService;
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
    ) {}

    /**
     * Menangani alert EWS. Pangkat/KGB membuat riwayat baru, sedangkan Pensiun
     * mengarsipkan SK dan mengubah status pegawai dalam transaksi yang sama.
     */
    public function execute(EwsAlert $alert, string $followupStatus, string $handledNote, Request $request): EwsAlert
    {
        $before = [];
        $employee = null;

        $alert = DB::transaction(function () use ($alert, $followupStatus, $handledNote, $request, &$before, &$employee): EwsAlert {
            $alert = EwsAlert::query()->lockForUpdate()->findOrFail($alert->id);
            if ($alert->followup_status !== EwsAlert::FOLLOWUP_STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'followup_status' => 'Alert EWS hanya dapat ditindaklanjuti saat status masih aktif.',
                ]);
            }

            $before = $this->alertSnapshot($alert);
            $employee = Employee::query()->lockForUpdate()->findOrFail($alert->employee_id);

            if ($followupStatus === EwsAlert::FOLLOWUP_STATUS_HANDLED) {
                if (in_array($alert->type, ['KENAIKAN_PANGKAT', 'KGB'], true)) {
                    $this->createApprovedHistory($employee, $alert->type, $request);
                    $this->resolveCurrentTypeAlerts($employee, $alert->type, $followupStatus, $handledNote, $request);
                } elseif ($alert->type === 'PENSIUN') {
                    $this->approveRetirement($employee, $request);
                    $this->resolveCurrentTypeAlerts($employee, $alert->type, $followupStatus, $handledNote, $request);
                } else {
                    $alert->update($this->followupAttributes($followupStatus, $handledNote, $request));
                }
            } elseif ($followupStatus === EwsAlert::FOLLOWUP_STATUS_NOT_NEEDED) {
                // Tidak mengubah riwayat maupun status pegawai; hanya menutup EWS terkait.
                $this->resolveCurrentTypeAlerts($employee, $alert->type, $followupStatus, $handledNote, $request);
            }

            return $alert;
        });

        $alert->refresh();
        AuditService::log('UPDATE', 'EwsAlert', $alert->id, $before, $this->alertSnapshot($alert), $request);

        if ($followupStatus === EwsAlert::FOLLOWUP_STATUS_NOT_NEEDED && $employee instanceof Employee) {
            $this->notifications->createForEmployee(
                $employee,
                'ews.tidak_perlu',
                'Tindak Lanjut EWS: Tidak Perlu',
                $handledNote,
                [
                    'ews_alert_id' => $alert->id,
                    'followup_status' => EwsAlert::FOLLOWUP_STATUS_NOT_NEEDED,
                    'event_type' => $alert->type,
                ],
            );
        }

        return $alert;
    }

    private function createApprovedHistory(Employee $employee, string $type, Request $request): void
    {
        if ($type === 'KENAIKAN_PANGKAT') {
            $tmt = (string) $request->input('tmt_pangkat');
            $this->ensureNewerTmt($employee, 'rankHistories', 'tmt_pangkat', $tmt, 'tmt_pangkat');
            $this->histories->createRankHistory($employee, [
                'golongan_id' => (string) $request->input('golongan_id'),
                'tmt_pangkat' => $tmt,
                'no_sk' => (string) $request->input('no_sk'),
                'tanggal_sk' => (string) $request->input('tanggal_sk'),
                'file_sk' => $request->file('file_sk'),
            ], $request);

            return;
        }

        $tmt = (string) $request->input('tmt_kgb');
        $this->ensureNewerTmt($employee, 'salaryHistories', 'tmt_kgb', $tmt, 'tmt_kgb');
        $this->histories->createKgbHistory($employee, [
            'tmt_kgb' => $tmt,
            'gaji_pokok' => $request->input('gaji_pokok'),
            'no_sk' => (string) $request->input('no_sk'),
            'tanggal_sk' => (string) $request->input('tanggal_sk'),
            'file_sk' => $request->file('file_sk'),
        ], $request);
    }

    private function approveRetirement(Employee $employee, Request $request): void
    {
        $oldValues = $employee->getAttributes();
        $pensionStatus = RefStatusPegawai::query()->where('kode', 'PENSIUN')->firstOrFail();
        $filePath = $this->files->storeSk($request->file('file_sk'));

        $employee->documents()->create([
            'jenis_dokumen' => 'sk_pensiun',
            'nama_dokumen' => 'SK Pensiun',
            'nomor_dokumen' => (string) $request->input('no_sk'),
            'tanggal_dokumen' => (string) $request->input('tanggal_sk'),
            'file_path' => $filePath,
            'keterangan' => 'Diunggah saat persetujuan EWS Pensiun.',
        ]);
        $employee->update(['status_pegawai_id' => $pensionStatus->id]);

        AuditService::log('UPDATE', 'Employee', $employee->id, $oldValues, $employee->fresh()->getAttributes(), $request);
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

    private function resolveCurrentTypeAlerts(Employee $employee, string $type, string $status, string $note, Request $request): void
    {
        $alerts = EwsAlert::query()
            ->where('employee_id', $employee->id)
            ->where('type', $type)
            ->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();
        $alertIds = $alerts->modelKeys();
        $now = now();

        EwsAlert::query()->whereKey($alertIds)->update($this->followupAttributes($status, $note, $request, $now));
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
    private function followupAttributes(string $status, string $note, Request $request, mixed $handledAt = null): array
    {
        $handledAt ??= now();

        return [
            'followup_status' => $status,
            'handled_at' => $handledAt,
            'handled_by' => $request->user()?->id,
            'handled_note' => $note,
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
