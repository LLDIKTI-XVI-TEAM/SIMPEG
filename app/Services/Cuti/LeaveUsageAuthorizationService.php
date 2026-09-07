<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeDashboardScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class LeaveUsageAuthorizationService
{
    public function __construct(private readonly EmployeeDashboardScopeService $scope) {}

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

    /** @return Builder<Employee> */
    public function employeeScope(User $actor): Builder
    {
        return $this->scope->for($actor);
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new AuthorizationException('Aksi ini hanya tersedia untuk pengguna yang memiliki permission terkait.');
        }
    }
}
