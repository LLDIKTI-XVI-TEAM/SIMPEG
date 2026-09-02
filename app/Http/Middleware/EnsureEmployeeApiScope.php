<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Services\Employees\KepalaBagianScopeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployeeApiScope
{
    /** Role ber-permission boleh memakai surface API pegawai lintas pegawai. */
    private const MANAGER_ROLES = ['super_admin', 'admin_kepegawaian', 'pimpinan'];

    /**
     * RBAC menentukan aksi yang boleh dilakukan, sedangkan middleware ini
     * menentukan rekam pegawai mana yang boleh menjadi target API. Pegawai
     * hanya boleh memakai endpoint generik ini untuk data miliknya sendiri.
     * Pimpinan tetap memakai surface khusus yang sudah dibatasi payload-nya.
     * Kepala Bagian dibatasi pada bawahan langsung via KepalaBagianScopeService.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $effectiveRole = $user?->getEffectiveRole();

        if ($user === null || $effectiveRole === null) {
            abort(403);
        }

        if (in_array($effectiveRole, self::MANAGER_ROLES, true)) {
            return $next($request);
        }

        $target = $request->route('employee') ?? $request->route('id') ?? $request->route('employeeId');
        $targetEmployeeId = $target instanceof Employee ? $target->id : $target;

        if ($effectiveRole === 'kepala_bagian') {
            abort_unless(is_string($targetEmployeeId) && $targetEmployeeId !== '', 403);

            $scope = app(KepalaBagianScopeService::class);
            abort_unless($scope->hasDirectReport($user, $targetEmployeeId), 403);

            return $next($request);
        }

        abort_unless(
            $effectiveRole === 'pegawai'
                && is_string($user->employee_id)
                && is_string($targetEmployeeId)
                && hash_equals($user->employee_id, $targetEmployeeId),
            403,
        );

        return $next($request);
    }
}
