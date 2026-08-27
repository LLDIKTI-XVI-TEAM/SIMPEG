<?php

namespace App\Console\Commands;

use App\Actions\Cuti\ReconcileAnnualLeaveAnniversaryAction;
use App\Services\Cuti\AnnualLeaveBusinessClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ReconcileAnnualLeaveAnniversaryEntitlements extends Command
{
    protected $signature = 'cuti:reconcile-anniversary-entitlements
        {--limit=100 : Maksimum projection saldo yang diproses dalam satu run}';

    protected $description = 'Memulihkan hak cuti tahunan pada projection nol setelah anniversary masa kerja.';

    /** Menjalankan rekonsiliasi harian bounded berdasarkan tanggal bisnis WITA. */
    public function handle(
        ReconcileAnnualLeaveAnniversaryAction $reconcile,
        AnnualLeaveBusinessClock $businessClock,
    ): int {
        // Nol di depan dinormalisasi agar kompatibel tanpa membiarkan cast integer overflow.
        $rawLimit = (string) $this->option('limit');
        $normalizedLimit = ltrim($rawLimit, '0');
        $limit = filter_var($normalizedLimit === '' ? '0' : $normalizedLimit, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1) {
            $this->error('Opsi --limit wajib berupa bilangan bulat positif.');

            return self::INVALID;
        }

        $result = $reconcile->execute(
            $businessClock->today(),
            $limit,
        );

        $this->info("Rekonsiliasi anniversary selesai untuk {$result['processed']} projection saldo.");
        $this->line("Berhasil: {$result['processed']}, gagal: {$result['failed']}.");

        foreach ($result['failures'] as $failure) {
            // Pesan exception tidak dicatat karena dapat memuat detail query atau data pegawai sensitif.
            Log::error('Rekonsiliasi anniversary pegawai gagal.', $failure);
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
