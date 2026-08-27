<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Before;

/** Melindungi setiap test migrasi destruktif dari database di luar target CI. */
trait GuardsDestructiveMigrationTestEnvironment
{
    /**
     * Menolak eksekusi sebelum body test agar test baru tidak dapat melewati gate destruktif.
     * Prioritas negatif menjaga bootstrap Laravel selesai sebelum koneksi database diperiksa.
     */
    #[Before(-100)]
    protected function guardDestructiveMigrationTestEnvironment(): void
    {
        $optIn = $_SERVER['SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS']
            ?? $_ENV['SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS']
            ?? getenv('SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS');

        if ($optIn !== 'true') {
            $this->markTestSkipped('Set SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS=true untuk menjalankan test migrasi destruktif.');
        }

        $environment = app()->environment();
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        if ($environment !== 'testing' || $driver !== 'pgsql' || $database !== 'simpeg_test') {
            $this->fail(sprintf(
                'Test migrasi destruktif ditolak: wajib APP_ENV=testing, driver pgsql, dan database simpeg_test; aktual environment=%s, driver=%s, database=%s.',
                $environment,
                $driver,
                $database,
            ));
        }
    }
}
