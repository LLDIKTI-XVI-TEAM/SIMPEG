<?php

namespace App\Console\Commands;

use App\Actions\Notifications\PurgeReadNotificationsAction;
use Illuminate\Console\Command;

class PurgeReadNotifications extends Command
{
    protected $signature = 'notifications:purge-read';

    protected $description = 'Menghapus permanen seluruh notifikasi yang sudah dibaca (kebijakan retensi global untuk efisiensi storage).';

    public function handle(PurgeReadNotificationsAction $action): int
    {
        $purged = $action->execute();

        $this->info("Notifikasi terbaca yang dihapus dari database: {$purged}.");

        return self::SUCCESS;
    }
}
