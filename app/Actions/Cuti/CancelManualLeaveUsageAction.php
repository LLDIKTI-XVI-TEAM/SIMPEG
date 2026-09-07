<?php

namespace App\Actions\Cuti;

use App\Models\LeaveUsageRecord;
use App\Models\User;
use App\Services\Cuti\LeaveUsageAuthorizationService;
use App\Services\Cuti\LeaveUsageOverlapService;
use App\Services\Cuti\LeaveUsageRecordService;
use App\Services\Cuti\LeaveUsageTextNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CancelManualLeaveUsageAction
{
    public function __construct(
        private readonly LeaveUsageAuthorizationService $authorization,
        private readonly LeaveUsageOverlapService $overlap,
        private readonly LeaveUsageRecordService $records,
        private readonly LeaveUsageTextNormalizer $text,
    ) {}

    /**
     * Membatalkan fakta manual sebagai mutasi status-only tanpa mengubah bukti historis.
     */
    public function execute(
        string $current,
        string $correctionReason,
        User $actor,
        ?Request $request = null,
    ): LeaveUsageRecord {
        $this->authorization->assertCanManageManual($actor);
        $current = $this->resolveManualUsage($current, $actor);
        $reason = Validator::make(['correction_reason' => $correctionReason], [
            'correction_reason' => ['required', 'string', 'max:2000'],
        ])->validate()['correction_reason'];
        $reason = $this->text->required(
            (string) $reason,
            'correction_reason',
            'Alasan pembatalan wajib diisi.',
        );

        return DB::transaction(function () use ($current, $reason, $actor, $request): LeaveUsageRecord {
            $this->overlap->lockEmployee($current->employee_id);
            $lockedCurrent = LeaveUsageRecord::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();

            if ($lockedCurrent->source_type !== LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL
                || $lockedCurrent->record_status !== LeaveUsageRecord::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['usage_record' => 'Hanya fakta manual aktif yang dapat dibatalkan.']);
            }

            return $this->records->cancel(
                $lockedCurrent,
                $reason,
                $actor,
                $request,
                ['operation' => 'manual_usage_cancelled'],
            );
        });
    }

    private function resolveManualUsage(string $current, User $actor): LeaveUsageRecord
    {
        abort_unless(Str::isUuid($current), 404);

        return LeaveUsageRecord::query()
            ->whereKey($current)
            ->whereIn('employee_id', $this->authorization->employeeScope($actor)->select('employees.id'))
            ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
            ->firstOrFail();
    }
}
