<?php

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
