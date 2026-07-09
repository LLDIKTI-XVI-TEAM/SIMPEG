<?php

use App\Models\EwsConfig;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Status aktif hukuman disiplin disegarkan harian agar tab pegawai tidak menampilkan sanksi kedaluwarsa sebagai aktif.
Schedule::command('discipline-records:deactivate-expired')
    ->dailyAt('07:00')
    ->timezone(config('app.timezone'));

$ewsSchedulerTime = (string) EwsConfig::getVal('ews_scheduler_time', '07:00');
if (! preg_match('/^\d{2}:\d{2}$/', $ewsSchedulerTime)) {
    $ewsSchedulerTime = '07:00';
}

Schedule::command('app:run-ews')
    ->dailyAt($ewsSchedulerTime)
    ->timezone('Asia/Makassar');
