<?php

namespace App\Actions\Laporan;

use App\Services\Laporan\EmployeeExportDataService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportPegawaiPdfAction
{
    /**
     * Batas baris PDF. Dibuka sebagai konstanta publik agar halaman pemicu
     * memakai angka yang sama dengan penegakan backend, bukan duplikat literal.
     */
    public const MAX_ROWS = 500;

    public function __construct(private readonly EmployeeExportDataService $employeeExportData) {}

    /**
     * Menghasilkan PDF daftar pegawai. Jumlah baris berlebih ditolak agar tidak
     * menghabiskan memori server.
     *
     * @param  array<string, mixed>  $filters
     */
    public function execute(array $filters): Response|RedirectResponse|StreamedResponse
    {
        if (! array_key_exists('status', $filters) && ! array_key_exists('status_pegawai_id', $filters)) {
            $filters['status'] = 'Aktif';
        }

        $rows = $this->employeeExportData->rows($filters, defaultToActive: false);

        if ($rows->count() > self::MAX_ROWS) {
            return back()->with(
                'error',
                "Laporan memuat {$rows->count()} baris, melebihi batas ".self::MAX_ROWS.'. Persempit filter lalu coba lagi.',
            );
        }

        $pdf = Pdf::loadView('admin.laporan.pdf-pegawai', ['rows' => $rows])
            ->setPaper('a4', 'landscape');

        $filename = 'Laporan_Pegawai_'.now()->format('Ymd').'.pdf';

        return $pdf->download($filename);
    }
}
