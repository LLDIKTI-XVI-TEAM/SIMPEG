<?php

namespace App\Services\Cuti;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class AnnualLeaveBusinessClock
{
    public const TIMEZONE = 'Asia/Makassar';

    /** Menghasilkan waktu bisnis cuti LLDIKTI XVI tanpa bergantung pada timezone proses aplikasi. */
    public function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    /** Menghasilkan awal hari bisnis WITA untuk proses scheduler berbasis tanggal. */
    public function today(): Carbon
    {
        return $this->now()->startOfDay();
    }

    /** Menetapkan tahun saldo berdasarkan kalender bisnis WITA. */
    public function currentYear(): int
    {
        return $this->now()->year;
    }

    /** Menormalkan instant eksternal ke waktu bisnis sebelum tanggal cutoff diturunkan. */
    public function inBusinessTimezone(CarbonInterface $instant): Carbon
    {
        return Carbon::instance($instant)->setTimezone(self::TIMEZONE);
    }
}
