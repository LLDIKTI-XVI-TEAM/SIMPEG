<?php

namespace App\Console\Commands;

use App\Actions\Notifications\RecoverStaleWhatsAppDispatchesAction;
use Illuminate\Console\Command;

class RecoverStaleWhatsAppDispatches extends Command
{
    protected $signature = 'whatsapp:recover-dispatches
                            {--limit=50 : Maksimum outbox stale yang diperiksa per run}';

    protected $description = 'Memulihkan publish job WhatsApp dari outbox durable yang stale.';

    public function handle(RecoverStaleWhatsAppDispatchesAction $action): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > RecoverStaleWhatsAppDispatchesAction::MAX_LIMIT) {
            $this->error('Limit recovery WhatsApp harus berupa angka antara 1 dan 100.');

            return self::INVALID;
        }

        $result = $action->execute($limit);
        $this->info("Recovery dispatch WhatsApp selesai. Diperiksa: {$result['scanned']}, dipublish: {$result['published']}.");

        return self::SUCCESS;
    }
}
