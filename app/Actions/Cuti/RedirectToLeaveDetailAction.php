<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Cuti\LeaveRequestReadAccess;

/** URL detail role dipertahankan sebagai adapter, bukan renderer atau jalur otorisasi kedua. */
final class RedirectToLeaveDetailAction
{
    public function __construct(private readonly LeaveRequestReadAccess $readAccess) {}

    /** Konteks hanya menentukan navigasi kembali; tidak pernah memberikan akses ke record. */
    public function execute(LeaveRequest $leave, ?User $actor, string $from): string
    {
        abort_unless($this->readAccess->canReadDetail($leave, $actor), 403);

        return route('cuti.show', [
            'id' => $leave->id,
            'from' => in_array($from, ['pimpinan', 'bawahan'], true) ? $from : null,
        ]);
    }
}
