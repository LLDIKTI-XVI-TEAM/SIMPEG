<?php

namespace App\Actions\Employees;

use App\Models\ImportBatch;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadImportReportAction
{
    /**
     * Bangun laporan hasil import sebagai CSV dari data batch yang dipersist,
     * bukan dari state browser, agar laporan tetap akurat dan dapat diunduh
     * kapan pun setelah proses selesai (US-3.4 AC-4).
     */
    public function execute(ImportBatch $batch): StreamedResponse
    {
        $filename = 'laporan_import_pegawai_'.($batch->finished_at ?? $batch->created_at)?->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($batch): void {
            $output = fopen('php://output', 'w');
            // BOM agar Excel membaca UTF-8 dengan benar.
            echo "\xEF\xBB\xBF";

            fputcsv($output, ['Laporan Hasil Import Pegawai']);
            fputcsv($output, ['Berkas', $batch->filename]);
            fputcsv($output, ['Status', $batch->status]);
            fputcsv($output, ['Total baris', (string) $batch->total_rows]);
            fputcsv($output, ['Berhasil ditambahkan', (string) $batch->inserted_count]);
            fputcsv($output, ['Dilewati (NIP terdaftar)', (string) $batch->skipped_count]);
            fputcsv($output, ['Gagal', (string) $batch->failed_count]);
            if ($batch->error_message !== null) {
                fputcsv($output, ['Pesan kegagalan', $batch->error_message]);
            }
            fputcsv($output, []);

            fputcsv($output, ['Baris', 'Nama', 'Kategori', 'Kolom', 'Masalah']);
            foreach ($batch->row_issues ?? [] as $issue) {
                foreach (($issue['errors'] ?? []) as $column => $messages) {
                    foreach ((array) $messages as $message) {
                        fputcsv($output, [
                            (string) ($issue['row'] ?? '-'),
                            (string) ($issue['nama'] ?? '-'),
                            (string) ($issue['kategori'] ?? 'gagal'),
                            (string) $column,
                            (string) $message,
                        ]);
                    }
                }
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
