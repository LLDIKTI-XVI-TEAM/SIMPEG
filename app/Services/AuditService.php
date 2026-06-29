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
            $user = Auth::user();

            AuditLog::create([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'event' => $event,
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $request?->ip() ?? $ipAddress,
                'user_agent' => $request?->userAgent() ?? $userAgent,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit log gagal ditulis', [
                'event' => $event,
                'auditable_type' => $auditableType,
                'error' => $e->getMessage(),
            ]);
        }
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
            AuditLog::create([
                'user_id' => $userId,
                'user_name' => $userName,
                'event' => $event,
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $request?->ip() ?? $ipAddress,
                'user_agent' => $request?->userAgent() ?? $userAgent,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit log gagal ditulis', [
                'event' => $event,
                'auditable_type' => $auditableType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
