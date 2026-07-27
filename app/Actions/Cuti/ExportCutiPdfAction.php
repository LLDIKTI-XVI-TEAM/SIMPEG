<?php

namespace App\Actions\Cuti;

use App\Queries\Cuti\CutiRekapQuery;
use App\Support\Cuti\CutiReportStatusFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class ExportCutiPdfAction
{
    private const MAX_ROWS = 500;

    public function __construct(
        private readonly CutiRekapQuery $rekapQuery,
        private readonly CutiReportStatusFormatter $statusFormatter,
    ) {}

    /**
     * Menghasilkan PDF terbatas; jumlah berlebih ditolak agar proses render tidak menghabiskan memori server.
     *
     * @param  array<string, mixed>  $filters
     */
    public function execute(array $filters): Response|RedirectResponse
    {
        $query = $this->rekapQuery->detailRows($filters);
        $count = (clone $query)->count();

        if ($count > self::MAX_ROWS) {
            return back()->with(
                'error',
                "Laporan memuat {$count} baris, melebihi batas ".self::MAX_ROWS.'. Persempit filter lalu coba lagi.',
            );
        }

        $rows = $query->get();
        $rows->each(function ($row): void {
            $row->setAttribute('report_status', $this->statusFormatter->format($row));
        });

        $pdf = Pdf::loadView('admin.cuti.pdf.laporan-cuti', [
            'rows' => $rows,
            'summaryRows' => $this->rekapQuery->summaryRows($rows, $filters),
            'periodLabel' => $this->rekapQuery->periodLabel($filters),
            'filters' => $filters,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('Laporan_Cuti_'.now()->format('Ymd_His').'.pdf');
    }
}
