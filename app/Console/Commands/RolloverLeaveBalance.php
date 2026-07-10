<?php

namespace App\Console\Commands;

use App\Services\Cuti\LeaveBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RolloverLeaveBalance extends Command
{
    protected $signature = 'cuti:rollover {year? : Tahun sumber saldo yang ditutup}';

    protected $description = 'Menutup saldo cuti tahunan dari tahun sumber dan membuka saldo tahun berikutnya.';

    /**
     * Menjalankan rollover tahunan secara idempotent.
     * Argumen year adalah tahun sumber, bukan tahun target, agar scheduler 1 Januari menutup tahun sebelumnya.
     */
    public function handle(LeaveBalanceService $balances): int
    {
        $year = (string) ($this->argument('year') ?? Carbon::now(config('app.timezone'))->subYear()->year);

        if (! preg_match('/^\d{4}$/', $year)) {
            $this->error('Tahun sumber rollover harus berupa tahun 4 digit, contoh: 2026.');

            return self::FAILURE;
        }

        $sourceYear = (int) $year;
        $targetYear = $sourceYear + 1;

        $balances->rolloverYear($sourceYear);

        $this->info("Rollover saldo cuti tahun {$sourceYear} ke {$targetYear} selesai.");

        return self::SUCCESS;
    }
}
