<?php

namespace App\Actions\Ews;

use App\Models\EwsAlert;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UpdateEwsAlertFollowupAction
{
    /**
     * Menandai tindak lanjut alert EWS dan mencatat audit perubahan lifecycle.
     */
    public function execute(EwsAlert $alert, string $followupStatus, string $handledNote, Request $request): EwsAlert
    {
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

        $alert->update([
            'followup_status' => $followupStatus,
            'handled_at' => now(),
            'handled_by' => $request->user()?->id,
            'handled_note' => $handledNote,
            'is_processed' => true,
        ]);

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
}
