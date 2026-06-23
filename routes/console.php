<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Services\EwsEngineService;

Artisan::command('app:run-ews', function (EwsEngineService $ewsEngine) {
    $this->info('Starting EWS scan...');
    $ewsEngine->run();
    $this->info('EWS scan completed successfully!');
})->purpose('Scan active employees and create EWS alerts');
