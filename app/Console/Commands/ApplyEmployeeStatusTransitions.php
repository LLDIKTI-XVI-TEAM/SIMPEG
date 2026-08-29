<?php

namespace App\Console\Commands;

use App\Services\Employees\EmployeeStatusTransitionService;
use Illuminate\Console\Command;

class ApplyEmployeeStatusTransitions extends Command
{
    protected $signature = 'employees:apply-status-transitions
        {--as-of= : Tanggal eval (YYYY-MM-DD) untuk uji before/after scheduler}';

    protected $description = 'Menerapkan transisi status kepegawaian terjadwal yang sudah jatuh tempo (K-STATUS-06, Asia/Makassar).';

    public function handle(EmployeeStatusTransitionService $transitions): int
    {
        $asOf = $this->option('as-of') ?: null;
        $applied = $transitions->applyDue($asOf);
        $remaining = $transitions->remainingDueCount($asOf);

        $this->info("Transisi status terjadwal diterapkan: {$applied}.");

        if ($remaining > 0) {
            // Row yang gagal tetap pending agar retry berikutnya aman, tetapi exit nonzero
            // memastikan scheduler dan monitoring tidak menganggap run ini berhasil penuh.
            $this->warn("Transisi status terjadwal masih pending: {$remaining}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
