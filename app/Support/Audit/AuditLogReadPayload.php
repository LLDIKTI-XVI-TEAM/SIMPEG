<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Cuti\AdministrativeLeavePostponementAccess;
use Illuminate\Support\Collection;

/** Redaksi saat baca tidak mengubah snapshot audit immutable atau masker identitas pada sisi tulis. */
final class AuditLogReadPayload
{
    public function __construct(private readonly AdministrativeLeavePostponementAccess $access) {}

    /**
     * Resolusi scope dibatasi pada record halaman ini; caller tanpa aktor selalu fail-closed.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return Collection<string, array<array-key, mixed>>
     */
    public function forLogs(Collection $logs, ?User $actor): Collection
    {
        $privateLogs = $logs->filter(fn (AuditLog $log): bool => class_basename($log->auditable_type) === 'LeaveRequest'
            && data_get($log->new_values, 'operation') === 'administrative_postponement');
        $requestIds = $privateLogs->pluck('auditable_id')->filter()->unique()->values()->all();
        $allowed = $actor === null ? [] : $this->access->readableReasonRequestIds($requestIds, $actor);

        return $logs->mapWithKeys(function (AuditLog $log) use ($privateLogs, $allowed): array {
            $payload = $log->toArray();
            if ($privateLogs->contains('id', $log->id) && ! in_array($log->auditable_id, $allowed, true)) {
                foreach (['old_values', 'new_values'] as $field) {
                    if (is_array($payload[$field] ?? null) && array_key_exists('reason', $payload[$field])) {
                        $payload[$field]['reason'] = '[Alasan privat]';
                    }
                }
            }

            return [$log->id => $payload];
        });
    }
}
