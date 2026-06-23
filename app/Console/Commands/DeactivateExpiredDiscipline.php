<?php

namespace App\Console\Commands;

use App\Models\DisciplineRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class DeactivateExpiredDiscipline extends Command
{
    protected $signature = 'discipline-records:deactivate-expired';

    protected $description = 'Menonaktifkan riwayat hukuman disiplin aktif yang sudah melewati tanggal berakhir.';

    public function handle(): int
    {
        $count = DisciplineRecord::query()
            ->where('is_active', true)
            ->whereNotNull('tanggal_berakhir')
            ->whereDate('tanggal_berakhir', '<', Carbon::today())
            ->update(['is_active' => false]);

        $this->info("{$count} riwayat disiplin kedaluwarsa dinonaktifkan.");

        return self::SUCCESS;
    }
}
