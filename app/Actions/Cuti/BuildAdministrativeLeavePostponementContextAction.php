<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Cuti\AdministrativeLeavePostponementAccess;

final class BuildAdministrativeLeavePostponementContextAction
{
    public function __construct(private readonly AdministrativeLeavePostponementAccess $access) {}

    /**
     * Tiga detail memakai allowlist yang sama; hak monitoring tidak otomatis membuka alasan privat.
     * Caller tetap wajib menegakkan akses baca halaman sebelum meminta konteks ini.
     *
     * @return array{canAdministrativelyPostpone: bool, administrativePostponement: array{occurredAt: string, actorName: string|null, reason: string|null}|null}
     */
    public function execute(LeaveRequest $leave, User $actor): array
    {
        $decision = null;
        if ($leave->status === LeaveRequest::STATUS_ADMINISTRATIVELY_POSTPONED) {
            $canReadReason = $this->access->canReadReason($leave, $actor);
            if ($canReadReason) {
                $leave->loadMissing('administrativelyPostponedBy:id,name');
            }

            $decision = [
                'occurredAt' => $leave->administratively_postponed_at?->setTimezone('Asia/Makassar')->translatedFormat('d F Y, H:i').' WITA',
                'actorName' => $canReadReason ? ($leave->administrativelyPostponedBy?->name ?? 'Akun pengambil keputusan tidak tersedia') : null,
                'reason' => $canReadReason ? $leave->administrative_postponement_reason : null,
            ];
        }

        return [
            'canAdministrativelyPostpone' => $this->access->canPostpone($leave, $actor),
            'administrativePostponement' => $decision,
        ];
    }
}
