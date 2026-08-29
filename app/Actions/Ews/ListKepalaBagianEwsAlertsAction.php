<?php

namespace App\Actions\Ews;

use App\Models\User;
use App\Services\Employees\KepalaBagianScopeService;
use Illuminate\Pagination\LengthAwarePaginator;

class ListKepalaBagianEwsAlertsAction
{
    public function __construct(
        private readonly KepalaBagianScopeService $scope,
        private readonly ListActiveEwsAlertsAction $ewsAlerts,
    ) {}

    /**
     * @return array{alerts: LengthAwarePaginator, type_labels: array<string, string>, followup_status_labels: array<string, string>, summary: array{total: int, urgent: int, warning: int, info: int}, filterSearch: string}
     */
    public function execute(User $user, ?string $event, ?string $status, ?string $search = null, int $perPage = 10): array
    {
        $data = $this->ewsAlerts->paginate(
            $event,
            $status,
            $search,
            $perPage,
            null,
            $this->scope->directReportIds($user),
        );

        return [
            'alerts' => $data['alerts'],
            'type_labels' => $data['type_labels'],
            'followup_status_labels' => $data['followup_status_labels'],
            'summary' => $data['summary'],
            'filterSearch' => (string) $search,
        ];
    }
}
