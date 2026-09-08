<?php

namespace App\Actions\Audit;

use App\Models\User;
use App\Services\Audit\AuditLogScope;
use App\Support\Audit\AuditLogViewPayload;

final class ShowAuditLogAction
{
    public function __construct(private readonly AuditLogScope $scope) {}

    /**
     * ID yang diketahui tidak membuka audit di luar scope; alasan privat tetap disaring terpisah.
     *
     * @return array<string, mixed>
     */
    public function execute(string $id, ?User $actor): array
    {
        return AuditLogViewPayload::forReader($this->scope->for($actor)->findOrFail($id), $actor);
    }
}
