<?php

namespace App\Console\Commands;

use App\Actions\Notifications\PurgeReadEwsNotificationsAction;
use Illuminate\Console\Command;

class PurgeReadEwsNotifications extends Command
{
    protected $signature = 'notifications:purge-read-ews';

    protected $description = 'Menghapus permanen notifikasi EWS yang sudah dibaca lebih dari 10 menit.';

    public function handle(PurgeReadEwsNotificationsAction $action): int
    {
        $purged = $action->execute();

        $this->info("Notifikasi EWS yang dihapus: {$purged}.");

        return self::SUCCESS;
    }
}
