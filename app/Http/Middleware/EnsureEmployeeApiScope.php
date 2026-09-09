<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Services\Employees\KepalaBagianScopeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployeeApiScope
{
    /** Role ber-permission boleh memakai surface pegawai lintas pegawai. */
    private const MANAGER_ROLES = ['super_admin', 'admin_kepegawaian', 'pimpinan'];

    /**
     * Role yang boleh memakai endpoint API mentah lintas pegawai.
     * Pimpinan sengaja tidak termasuk: payload mentah memuat NIK dan relasi
     * penuh, sedangkan Pimpinan wajib memakai surface khusus yang payload-nya
     * sudah dibatasi.
     */
    private const STRICT_MANAGER_ROLES = ['super_admin', 'admin_kepegawaian'];

    /**
     * RBAC menentukan aksi yang boleh dilakukan, sedangkan middleware ini
     * menentukan rekam pegawai mana yang boleh menjadi target API. Pegawai
     * hanya boleh memakai endpoint generik ini untuk data miliknya sendiri.
     * Kepala Bagian dibatasi pada bawahan langsung via KepalaBagianScopeService.
     *
     * Mode `strict` dipakai endpoint API mentah (payload NIK/relasi penuh):
     * Pimpinan ditolak (403) dan wajib memakai surface khusus yang dimasking.
     * Halaman web memakai mode default karena controller-nya menyajikan
     * payload yang sudah dibatasi per role.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();
        $effectiveRole = $user?->getEffectiveRole();

        if ($user === null || $effectiveRole === null) {
            abort(403);
        }

        $managers = $mode === 'strict' ? self::STRICT_MANAGER_ROLES : self::MANAGER_ROLES;

        if (in_array($effectiveRole, $managers, true)) {
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

        if ($effectiveRole === 'pegawai') {
            abort_unless(
                is_string($user->employee_id)
                    && is_string($targetEmployeeId)
                    && hash_equals($user->employee_id, $targetEmployeeId),
                403,
            );

            return $next($request);
        }

        abort(403);
    }
}
