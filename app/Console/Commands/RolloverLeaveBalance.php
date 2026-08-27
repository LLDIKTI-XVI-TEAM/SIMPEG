<?php

namespace App\Console\Commands;

use App\Actions\Cuti\RolloverLeaveBalanceAction;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RolloverLeaveBalance extends Command
{
    protected $signature = 'cuti:rollover {year? : Tahun sumber saldo yang ditutup}';

    protected $description = 'Menutup saldo cuti tahunan dari tahun sumber dan membuka saldo tahun berikutnya.';

    /**
     * Menjalankan rollover tahunan secara idempotent.
     * Argumen year adalah tahun sumber, bukan tahun target, agar scheduler 1 Januari menutup tahun sebelumnya.
     */
    public function handle(RolloverLeaveBalanceAction $rollover): int
    {
        $year = (string) ($this->argument('year') ?? Carbon::now(config('app.timezone'))->subYear()->year);

        if (! preg_match('/^\d{4}$/', $year)) {
            $this->error('Tahun sumber rollover harus berupa tahun 4 digit, contoh: 2026.');

            return self::FAILURE;
        }

        $sourceYear = (int) $year;
        $targetYear = $sourceYear + 1;

        $result = $rollover->execute($sourceYear);

        $this->info("Rollover saldo cuti tahun {$sourceYear} ke {$targetYear} selesai.");
        $this->line("Berhasil: {$result['processed']}, gagal: {$result['failed']}.");

        foreach ($result['failures'] as $failure) {
            // Pesan exception tidak dicatat karena dapat memuat detail query atau data pegawai sensitif.
            Log::error('Rollover saldo cuti pegawai gagal.', $failure);
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
