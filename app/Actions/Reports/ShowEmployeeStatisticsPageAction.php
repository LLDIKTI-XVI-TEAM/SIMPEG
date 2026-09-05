<?php

namespace App\Actions\Reports;

use App\Models\User;
use App\Queries\Reports\EmployeeStatisticsQuery;
use App\Services\Employees\EmployeeDashboardScopeService;

class ShowEmployeeStatisticsPageAction
{
    public function __construct(
        private readonly EmployeeStatisticsQuery $statistics,
        private readonly EmployeeDashboardScopeService $scope,
    ) {}

    /**
     * @return array{
     *     total: int,
     *     summary: array{pns: int, pppk: int, cpns: int, laki_laki: int, perempuan: int},
     *     dimensions: array<string, list<array{label: string, total: int, tone: string}>>
     * }
     */
    public function execute(?User $actor): array
    {
        return $this->statistics->execute($this->scope->for($actor));
    }
}
