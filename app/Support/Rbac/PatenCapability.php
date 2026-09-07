<?php

namespace App\Support\Rbac;

/**
 * Capability domain yang selalu tersedia untuk pengguna sah pada konteksnya.
 *
 * Daftar ini sengaja dipisahkan dari permission RBAC agar matriks tidak dapat
 * memutus self-service, lifecycle cuti, atau keputusan approval-chain.
 */
final class PatenCapability
{
    /** @var list<string> */
    public const PERMISSION_NAMES = [
        'employees.read_self',
        'employee_histories.read',
        'employee_families.read',
        'notifications.read',
        'notifications.update',
        'hari_libur.read',
        'cuti.create',
        'cuti.read_own',
        'cuti.approve',
        'cuti.balance.read',
        'cuti.proof.generate',
    ];

    public static function isPermissionName(string $name): bool
    {
        return in_array($name, self::PERMISSION_NAMES, true);
    }
}
