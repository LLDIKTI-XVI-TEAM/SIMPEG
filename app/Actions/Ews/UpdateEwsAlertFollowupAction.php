<?php

namespace App\Actions\Ews;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\SimpegNotification;
use App\Services\AuditService;
use App\Services\EmployeeHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateEwsAlertFollowupAction
{
    public function __construct(private readonly EmployeeHistoryService $histories) {}

    /**
     * Menandai tindak lanjut alert EWS. Persetujuan Pangkat/KGB membuat riwayat
     * SK baru sehingga target EWS berikutnya otomatis dihitung ulang dari TMT.
     */
    public function execute(EwsAlert $alert, string $followupStatus, string $handledNote, Request $request): EwsAlert
    {
        $before = [];

        $alert = DB::transaction(function () use ($alert, $followupStatus, $handledNote, $request, &$before): EwsAlert {
            $alert = EwsAlert::query()->lockForUpdate()->findOrFail($alert->id);

            if ($alert->followup_status !== EwsAlert::FOLLOWUP_STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'followup_status' => 'Alert EWS hanya dapat ditindaklanjuti saat status masih aktif.',
                ]);
            }

            $before = [
                'followup_status' => $alert->followup_status,
                'handled_at' => $alert->handled_at?->toDateTimeString(),
                'handled_by' => $alert->handled_by,
                'handled_note' => $alert->handled_note,
            ];

            if ($followupStatus === EwsAlert::FOLLOWUP_STATUS_HANDLED
                && in_array($alert->type, ['KENAIKAN_PANGKAT', 'KGB'], true)) {
                $employee = Employee::query()->findOrFail($alert->employee_id);
                $this->createApprovedHistory($employee, $alert->type, $request);
                $this->resolveCurrentTypeAlerts($employee, $alert->type, $handledNote, $request);

                return $alert;
            }

            $alert->update($this->followupAttributes($followupStatus, $handledNote, $request));

            return $alert;
        });

        $alert->refresh();

        $after = [
            'followup_status' => $alert->followup_status,
            'handled_at' => $alert->handled_at?->toDateTimeString(),
            'handled_by' => $alert->handled_by,
            'handled_note' => $alert->handled_note,
        ];

        AuditService::log('UPDATE', 'EwsAlert', $alert->id, $before, $after, $request);

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

    private function ensureNewerTmt(Employee $employee, string $relation, string $column, string $tmt, string $attribute): void
    {
        $latestTmt = $employee->{$relation}()->max($column);
        if ($latestTmt !== null && Carbon::parse($tmt)->lessThanOrEqualTo(Carbon::parse($latestTmt))) {
            throw ValidationException::withMessages([
                $attribute => 'TMT baru harus lebih besar dari TMT riwayat terakhir agar target EWS dapat direset.',
            ]);
        }
    }

    private function resolveCurrentTypeAlerts(Employee $employee, string $type, string $handledNote, Request $request): void
    {
        $alerts = EwsAlert::query()
            ->where('employee_id', $employee->id)
            ->where('type', $type)
            ->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();
        $alertIds = $alerts->modelKeys();
        $now = now();

        EwsAlert::query()
            ->whereKey($alertIds)
            ->update($this->followupAttributes(EwsAlert::FOLLOWUP_STATUS_HANDLED, $handledNote, $request, $now));

        SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('is_read', false)
            ->where(function ($query) use ($alertIds): void {
                $query->whereIn('ews_alert_id', $alertIds);

                foreach ($alertIds as $alertId) {
                    $query->orWhereJsonContains('data->ews_alert_id', $alertId);
                }
            })
            ->update([
                'is_read' => true,
                'read_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /** @return array<string, mixed> */
    private function followupAttributes(string $followupStatus, string $handledNote, Request $request, mixed $handledAt = null): array
    {
        $handledAt ??= now();

        return [
            'followup_status' => $followupStatus,
            'handled_at' => $handledAt,
            'handled_by' => $request->user()?->id,
            'handled_note' => $handledNote,
            'is_processed' => true,
            'notification_acknowledged_at' => $handledAt,
        ];
    }
}
