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

    /** Permission efektif dapat didelegasikan; scope pemilik fakta diperiksa terpisah dari referensi approver. */
    public function assertCanManageManual(User $actor): void
    {
        if (! $actor->hasPermission('cuti.manual.manage')) {
            throw new AuthorizationException('Anda tidak memiliki izin mengelola pemakaian cuti manual.');
        }
    }

    /**
     * Memakai scope pegawai kanonis sebagai query agar UUID eksplisit tidak memperluas kewenangan aktor.
     * Caller tetap wajib memeriksa permission baca atau mutasi yang sesuai sebelum menjalankan query.
     *
     * @return Builder<Employee>
     */
    public function employeeScope(User $actor): Builder
    {
        return $this->scope->for($actor);
    }
}
