<?php

namespace App\Services\Cuti;

use Illuminate\Support\Facades\DB;

class ApprovalChainConfigurationLockService
{
    private const LOCK_KEY = 'simpeg.leave_chain_configuration';

    /**
     * Menserialkan seluruh writer konfigurasi rantai agar pemindaian massal tidak melewatkan chain baru.
     *
     * Lock tingkat transaksi bersifat reentrant pada koneksi yang sama, sehingga writer massal dapat
     * memanggil SaveAction di dalam transaksinya tanpa menunggu dirinya sendiri.
     */
    public function acquire(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select(
            'select pg_advisory_xact_lock(hashtextextended(?, 0))',
            [self::LOCK_KEY],
        );
    }
}
