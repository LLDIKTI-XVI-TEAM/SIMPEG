<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuditService
{
    /**
     * Write an audit log entry.
     *
     * Fire-and-forget: failures are logged but never bubble up
     * to break the main operation.
     *
     * @param  string  $event  One of the audit_logs.event enum values
     * @param  string  $auditableType  Short model name, e.g. 'User', 'Employee'
     * @param  string|null  $auditableId  UUID of the affected record
     * @param  array|null  $oldValues  Previous state (for UPDATE / DELETE)
     * @param  array|null  $newValues  New state (for CREATE / UPDATE / IMPORT)
     * @param  Request|null  $request  Current HTTP request (for IP & UA)
     */
    public static function log(
        string $event,
        string $auditableType,
        ?string $auditableId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        try {
            AuditLog::create(self::authenticatedPayload(
                $event,
                $auditableType,
                $auditableId,
                $oldValues,
                $newValues,
                $request,
                $ipAddress,
                $userAgent,
            ));
        } catch (\Throwable $e) {
            Log::warning('Audit log gagal ditulis', [
                'event' => $event,
                'auditable_type' => $auditableType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Menulis audit kritis tanpa menelan kegagalan agar transaksi domain dapat di-rollback.
     */
    public static function logOrFail(
        string $event,
        string $auditableType,
        ?string $auditableId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        AuditLog::create(self::authenticatedPayload(
            $event,
            $auditableType,
            $auditableId,
            $oldValues,
            $newValues,
            $request,
            $ipAddress,
            $userAgent,
        ));
    }

    /**
     * Convenience: log with explicit user data (for cases where Auth::user() is
     * not yet available, e.g. right after login before the session is written).
     */
    public static function logAs(
        string $userId,
        string $userName,
        string $event,
        string $auditableType,
        ?string $auditableId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        try {
            AuditLog::create(self::explicitPayload(
                $userId,
                $userName,
                $event,
                $auditableType,
                $auditableId,
                $oldValues,
                $newValues,
                $request,
                $ipAddress,
                $userAgent,
            ));
        } catch (\Throwable $e) {
            Log::warning('Audit log gagal ditulis', [
                'event' => $event,
                'auditable_type' => $auditableType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Menulis audit dengan aktor eksplisit tanpa menelan kegagalan agar mutasi kritis dapat di-rollback.
     */
    public static function logAsOrFail(
        string $userId,
        string $userName,
        string $event,
        string $auditableType,
        ?string $auditableId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        AuditLog::create(self::explicitPayload(
            $userId,
            $userName,
            $event,
            $auditableType,
            $auditableId,
            $oldValues,
            $newValues,
            $request,
            $ipAddress,
            $userAgent,
        ));
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @return array<string, mixed>
     */
    private static function authenticatedPayload(
        string $event,
        string $auditableType,
        ?string $auditableId,
        ?array $oldValues,
        ?array $newValues,
        ?Request $request,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        $user = Auth::user();

        // Sertakan konteks simulasi role bila user sedang berada dalam mode switch role
        if ($user && $user->temporary_role) {
            $simulationMeta = [
                '_simulation' => true,
                '_original_role' => $user->role,
                '_effective_role' => $user->getEffectiveRole(),
            ];

            $newValues = is_array($newValues)
                ? array_merge($newValues, $simulationMeta)
                : $simulationMeta;
        }

        return [
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'event' => $event,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip() ?? $ipAddress,
            'user_agent' => $request?->userAgent() ?? $userAgent,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @return array<string, mixed>
     */
    private static function explicitPayload(
        string $userId,
        string $userName,
        string $event,
        string $auditableType,
        ?string $auditableId,
        ?array $oldValues,
        ?array $newValues,
        ?Request $request,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        return [
            'user_id' => $userId,
            'user_name' => $userName,
            'event' => $event,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip() ?? $ipAddress,
            'user_agent' => $request?->userAgent() ?? $userAgent,
        ];
    }
}
