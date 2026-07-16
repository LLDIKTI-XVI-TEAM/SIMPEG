<?php

namespace App\Actions\Cuti;

use App\Queries\Cuti\CutiRekapQuery;
use App\Support\Cuti\CutiReportStatusFormatter;

class ShowCutiReportPreviewAction
{
    public function __construct(
        private readonly CutiRekapQuery $rekapQuery,
        private readonly CutiReportStatusFormatter $statusFormatter,
    ) {}

    /**
     * Memakai query detail kanonis agar preview dan ekspor tidak berbeda hasil atau urutannya.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters): array
    {
        $rows = $this->rekapQuery->detailRows($filters)
            ->paginate(15)
            ->withQueryString();
        $rows->through(function ($row): mixed {
            $row->setAttribute('report_status', $this->statusFormatter->format($row));

            return $row;
        });

        return ['rows' => $rows, 'filters' => $filters];
    }
}
