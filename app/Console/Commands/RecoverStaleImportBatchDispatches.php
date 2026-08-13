<?php

namespace App\Console\Commands;

use App\Actions\Employees\RecoverStaleImportBatchDispatchesAction;
use Illuminate\Console\Command;

class RecoverStaleImportBatchDispatches extends Command
{
    protected $signature = 'import:recover-dispatches
                            {--limit=50 : Maksimum batch stale yang diperiksa per run}';

    protected $description = 'Memulihkan publish job untuk claim import queued yang stale.';

    /** Menjalankan reconciler bounded tanpa menampilkan payload atau detail exception. */
    public function handle(RecoverStaleImportBatchDispatchesAction $action): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > RecoverStaleImportBatchDispatchesAction::MAX_LIMIT) {
            $this->error('Limit recovery import harus berupa angka antara 1 dan 100.');

            return self::INVALID;
        }

        $result = $action->execute($limit);
        $this->info("Recovery dispatch import selesai. Diperiksa: {$result['scanned']}, dipublish: {$result['published']}.");

        return self::SUCCESS;
    }
}
