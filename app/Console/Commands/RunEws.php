<?php

namespace App\Console\Commands;

use App\Services\EwsEngineService;
use Illuminate\Console\Command;

class RunEws extends Command
{
    protected $signature = 'app:run-ews';

    protected $description = 'Menjalankan engine Early Warning System harian.';

    public function handle(EwsEngineService $engine): int
    {
        $engine->run();

        $this->info('EWS scheduler selesai dijalankan.');

        return self::SUCCESS;
    }
}
