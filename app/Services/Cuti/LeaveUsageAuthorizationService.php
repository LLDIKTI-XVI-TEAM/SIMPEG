<?php

namespace App\Services\Cuti;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class LeaveUsageAuthorizationService
{
    /** Menjaga mutasi fakta manual hanya dapat dijalankan pemegang permission yang masih berizin. */
    public function assertCanManageManual(User $actor): void
    {
        $this->assertPermission($actor, 'cuti.manual.manage');
    }

    /** Menjaga rekonsiliasi saldo hanya mengikuti permission terbarunya. */
    public function assertCanReconcile(User $actor): void
    {
        $this->assertPermission($actor, 'cuti.balance.reconcile');
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new AuthorizationException('Aksi ini hanya tersedia untuk pengguna yang memiliki permission terkait.');
        }
    }
}
