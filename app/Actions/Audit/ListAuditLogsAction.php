<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAuditLogsAction
{
    /**
     * Mengambil audit log immutable dengan filter yang sudah tersedia di endpoint lama.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $query = AuditLog::query()->orderByDesc('created_at');

        if (filled($filters['event'] ?? null)) {
            $query->where('event', $filters['event']);
        }

        if (filled($filters['from'] ?? null)) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }

        if (filled($filters['to'] ?? null)) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        if (filled($filters['user_id'] ?? null)) {
            $query->where('user_id', $filters['user_id']);
        }

        if (filled($filters['auditable_type'] ?? null)) {
            $query->where('auditable_type', $filters['auditable_type']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
    }
}
