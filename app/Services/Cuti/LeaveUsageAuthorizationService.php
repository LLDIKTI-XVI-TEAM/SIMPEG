<?php

namespace App\Services\Cuti;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class LeaveUsageAuthorizationService
{
    /** Menjaga mutasi fakta manual hanya dapat dijalankan role efektif Admin Kepegawaian yang masih berizin. */
    public function assertCanManageManual(User $actor): void
    {
        $this->assertExactPermission($actor, 'cuti.manual.manage');
    }

    private function assertExactPermission(User $actor, string $permission): void
    {
        if ($actor->getEffectiveRole() !== 'admin_kepegawaian' || ! $actor->hasPermission($permission)) {
            throw new AuthorizationException('Aksi ini hanya tersedia untuk Admin Kepegawaian yang berwenang.');
        }
    }
}
