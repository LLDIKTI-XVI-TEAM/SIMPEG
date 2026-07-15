<?php

namespace App\Actions\Ews;

use App\Models\User;
use App\Services\Employees\KepalaBagianScopeService;

class ListKepalaBagianEwsAlertsAction
{
    public function __construct(
        private readonly KepalaBagianScopeService $scope,
        private readonly ListActiveEwsAlertsAction $ewsAlerts,
    ) {}

    /**
     * @return array{alerts: array<int, array<string, mixed>>, type_labels: array<string, string>, followup_status_labels: array<string, string>}
     */
    public function execute(User $user, ?string $event, ?string $status): array
    {
        return $this->ewsAlerts->executeForEmployees(
            $this->scope->directReportIds($user),
            $event,
            $status,
        );
    }
}
