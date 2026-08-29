<?php

use App\Models\EwsConfig;
use App\Services\Cuti\AnnualLeaveBusinessClock;
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

// K-STATUS-06: transisi status terjadwal (future effective date) diterapkan otomatis
// setiap 5 menit dalam zona Asia/Makassar. Idempoten & concurrency-safe (lock baris +
// is_applied) sehingga retry/double worker tidak menciptakan history ganda.
Schedule::command('employees:apply-status-transitions')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->timezone('Asia/Makassar');

// Command menghitung tahun sumber saat dieksekusi agar aman untuk cron maupun scheduler worker yang berjalan lama.
Schedule::command('cuti:rollover')
    ->yearlyOn(1, 1, '00:05')
    // Mutex dua jam mencegah dua scheduler mereplay pegawai yang sama ketika proses tahunan masih berjalan.
    ->withoutOverlapping(120)
    ->timezone(config('app.timezone'));

// Projection nol direplay harian agar anniversary yang terlewat saat downtime tetap dipulihkan.
Schedule::command('cuti:reconcile-anniversary-entitlements --limit=100')
    ->dailyAt('00:15')
    ->withoutOverlapping(30)
    ->timezone(AnnualLeaveBusinessClock::TIMEZONE);

// Reconciler bounded memulihkan claim import yang commit tetapi kehilangan publish queue.
Schedule::command('import:recover-dispatches --limit=50')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->timezone(config('app.timezone'));

// Outbox terenkripsi memastikan delivery WhatsApp yang sudah commit tetapi gagal
// dipublikasikan ke queue dapat dipulihkan tanpa menyimpan nomor atau isi pesan di audit.
Schedule::command('whatsapp:recover-dispatches --limit=50')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->timezone(config('app.timezone'));

// Recovery bounded memulihkan file privat yatim atau adoption yang terputus oleh hard crash.
Schedule::command('storage:retry-recovery --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->timezone(config('app.timezone'));

// Setelah jam konfigurasi tercapai, EWS diperiksa ulang setiap lima menit sampai
// akhir hari. Waktu dibaca saat task dievaluasi agar perubahan konfigurasi
// langsung berlaku pada scheduler worker yang berjalan terus-menerus.
Schedule::command('app:run-ews')
    ->everyFiveMinutes()
    // Satu siklus pemindaian EWS bisa lebih lama dari interval lima menit saat data
    // pegawai besar. Tanpa proteksi overlap, dua proses scheduler dapat berjalan
    // bersamaan dan menghasilkan alert/notifikasi ganda. TTL mutex dibatasi 30 menit
    // supaya proses yang mati paksa (deploy/restart) tidak memblokir run berikutnya
    // selama 24 jam default.
    ->withoutOverlapping(30)
    ->timezone('Asia/Makassar')
    ->when(function (): bool {
        $ewsSchedulerTime = (string) EwsConfig::getVal('ews_scheduler_time', '07:00');
        if (! preg_match('/^\d{2}:\d{2}$/', $ewsSchedulerTime)) {
            $ewsSchedulerTime = '07:00';
        }

        return now('Asia/Makassar')->format('H:i') >= $ewsSchedulerTime;
    });
