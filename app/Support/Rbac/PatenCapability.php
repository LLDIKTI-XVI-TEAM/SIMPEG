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
    public const EMPLOYEE_SELF_PERMISSION_NAMES = [
        'employees.read_self',
        'cuti.create',
        'cuti.read_own',
    ];

    /** @var list<string> */
    public const USER_CONTEXT_PERMISSION_NAMES = [
        'notifications.read',
        'notifications.update',
        'hari_libur.read',
    ];

    /** @var list<string> */
    public const RECORD_CONTEXT_PERMISSION_NAMES = [
        'cuti.approve',
        'cuti.proof.generate',
    ];

    /** @var list<string> */
    public const PERMISSION_NAMES = [
        ...self::EMPLOYEE_SELF_PERMISSION_NAMES,
        ...self::USER_CONTEXT_PERMISSION_NAMES,
        ...self::RECORD_CONTEXT_PERMISSION_NAMES,
    ];

    public static function isPermissionName(string $name): bool
    {
        return in_array($name, self::PERMISSION_NAMES, true);
    }

    public static function requiresActiveEmployee(string $name): bool
    {
        return in_array($name, self::EMPLOYEE_SELF_PERMISSION_NAMES, true);
    }

    public static function isUserContextCapability(string $name): bool
    {
        return in_array($name, self::USER_CONTEXT_PERMISSION_NAMES, true);
    }

    public static function requiresRecordContext(string $name): bool
    {
        return in_array($name, self::RECORD_CONTEXT_PERMISSION_NAMES, true);
    }
}
