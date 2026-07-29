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
     * @return array{alerts: LengthAwarePaginator, type_labels: array<string, string>, followup_status_labels: array<string, string>, raw_alerts: array<int, array<string, mixed>>}
     */
    public function execute(User $user, ?string $event, ?string $status, int $perPage = 10): array
    {
        $data = $this->ewsAlerts->executeForEmployees(
            $this->scope->directReportIds($user),
            $event,
            $status,
        );

        $alerts = collect($data['alerts']);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginated = new LengthAwarePaginator(
            $alerts->forPage($page, $perPage)->values(),
            $alerts->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
        $paginated->withQueryString();

        return [
            'alerts' => $paginated,
            'type_labels' => $data['type_labels'],
            'followup_status_labels' => $data['followup_status_labels'],
            'raw_alerts' => $data['alerts'],
        ];
    }
}
