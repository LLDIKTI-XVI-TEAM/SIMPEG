<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogScope;
use App\Support\Audit\AuditFilterValue;
use App\Support\Audit\AuditLogReadPayload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAuditLogsAction
{
    public function __construct(
        private readonly AuditLogReadPayload $payload,
        private readonly AuditLogScope $scope,
    ) {}

    /**
     * Mengambil audit log immutable dengan filter yang sudah tersedia di endpoint lama.
     *
     * Nilai tanggal dan pengenal dibersihkan lebih dahulu karena kolomnya bertipe ketat di
     * PostgreSQL, sehingga nilai yang tidak berbentuk akan ditolak basis data dan berakhir
     * sebagai galat peladen alih-alih hasil kosong.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<array-key, mixed>>
     */
    public function execute(array $filters, ?User $actor = null): LengthAwarePaginator
    {
        $query = $this->scope->for($actor)->orderByDesc('created_at');

        $event = AuditFilterValue::text($filters['event'] ?? null);
        $from = AuditFilterValue::date($filters['from'] ?? null);
        $to = AuditFilterValue::date($filters['to'] ?? null);
        $userId = AuditFilterValue::uuid($filters['user_id'] ?? null);
        $auditableType = AuditFilterValue::text($filters['auditable_type'] ?? null);

        if ($event !== '') {
            $query->where('event', $event);
        }

        if ($from !== '') {
            $query->where('created_at', '>=', $from.' 00:00:00');
        }

        if ($to !== '') {
            $query->where('created_at', '<=', $to.' 23:59:59');
        }

        if ($userId !== '') {
            $query->where('user_id', $userId);
        }

        if ($auditableType !== '') {
            $query->where('auditable_type', $auditableType);
        }

        $perPage = min((int) ($filters['per_page'] ?? 10), 100);

        $paginator = $query->paginate($perPage);
        $payloads = $this->payload->forLogs($paginator->getCollection(), $actor);

        return $paginator->through(fn (AuditLog $log): array => $payloads->get($log->id));
    }
}
