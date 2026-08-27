<?php

namespace App\Actions\Cuti;

use App\Queries\Cuti\CutiRekapQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class ExportCutiPdfAction
{
    private const MAX_ROWS = 500;

    public function __construct(
        private readonly CutiRekapQuery $rekapQuery,
    ) {}

    /**
     * Menghasilkan PDF terbatas; jumlah berlebih ditolak agar proses render tidak menghabiskan memori server.
     *
     * @param  array<string, mixed>  $filters
     */
    public function execute(array $filters): Response|RedirectResponse
    {
        $detailCount = $this->rekapQuery->detailCount($filters);

        if ($detailCount > self::MAX_ROWS) {
            return back()->with(
                'error',
                "Laporan memuat {$detailCount} baris, melebihi batas ".self::MAX_ROWS.'. Persempit filter lalu coba lagi.',
            );
        }

        $summaryCount = $this->rekapQuery->summaryCount($filters);
        $totalRows = $detailCount + $summaryCount;

        if ($totalRows > self::MAX_ROWS) {
            return back()->with(
                'error',
                "Laporan memuat {$totalRows} baris, melebihi batas ".self::MAX_ROWS.'. Persempit filter lalu coba lagi.',
            );
        }

        $rows = $this->rekapQuery->allDetailRows($filters);
        $summaryRows = $this->rekapQuery->summaryRows($filters);

        $pdf = Pdf::loadView('admin.cuti.pdf.laporan-cuti', [
            'rows' => $rows,
            'summaryRows' => $summaryRows,
            'periodLabel' => $this->rekapQuery->periodLabel($filters),
            'filters' => $filters,
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape');

        $periodLabel = $this->rekapQuery->periodLabel($filters);

        return $pdf->download('Rekap_Cuti_'.$periodLabel.'_'.now()->format('Ymd').'.pdf');
    }
}
