<?php

namespace App\Services\Employees;

use App\Models\User;

/** Menjaga invariant otorisasi lifecycle pegawai (kontrak permission-driven). */
final class EmployeeLifecycleAuthorization
{
    public const RESTORE_PERMISSION = 'employees.restore';

    /**
     * Reaktivasi pegawai permission-driven: cukup permission employees.restore pada
     * role efektif. Allowlist role Super Admin/Admin Kepegawaian dihapus — kewenangan
     * reaktivasi kini sepenuhnya dikelola lewat RBAC matrix (kontrak permission-driven
     * modul employees); role tanpa permission tetap fail-closed, dan simulasi role
     * tidak dibypass karena hasPermission() berbasis role efektif.
     */
    public function canRestore(?User $user): bool
    {
        return $user instanceof User && $user->hasPermission(self::RESTORE_PERMISSION);
    }

    /**
     * Invariant lifecycle per permission. Allowlist role untuk reaktivasi sudah
     * dihapus: kesesuaian permission pada snapshot provenance divalidasi pemanggil
     * (EmployeeStatusActorContext::assertAuthorizes), sehingga tidak ada role
     * tambahan yang perlu diizinkan di sini — semua permission lolos dan bergantung
     * pada pemeriksaan hasPermission() di jalur request.
     */
    public function effectiveRoleAllows(?string $effectiveRole, string $permission): bool
    {
        return true;
    }
}
