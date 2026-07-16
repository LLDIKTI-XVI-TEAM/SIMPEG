<?php

namespace App\Console\Commands;

use App\Actions\Cuti\PurgeKepalaLembagaSupportingDocumentFilesAction;
use Illuminate\Console\Command;

class PurgeKepalaLembagaSupportingDocumentFiles extends Command
{
    protected $signature = 'cuti:purge-dokumen-kepala-lembaga';

    protected $description = 'Retry penghapusan fisik file dokumen pendukung cuti Kepala Lembaga yang sudah tombstoned.';

    /** Menjalankan retry idempoten yang aman dipanggil berulang oleh IT/ops. */
    public function handle(PurgeKepalaLembagaSupportingDocumentFilesAction $action): int
    {
        $purged = $action->execute();

        $this->info("Retry selesai. File fisik dihapus: {$purged}.");

        return self::SUCCESS;
    }
}
