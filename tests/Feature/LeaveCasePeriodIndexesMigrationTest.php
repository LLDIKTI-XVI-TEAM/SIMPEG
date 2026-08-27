<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class LeaveCasePeriodIndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MANUAL_INDEX = 'leave_usage_manual_case_period_index';

    private const MIGRATION_FILE = '2026_08_18_000008_add_leave_case_period_indexes.php';

    private const REQUEST_INDEX = 'leave_requests_case_status_period_index';

    public function test_fresh_schema_memiliki_index_periode_case_request_dan_manual(): void
    {
        $this->requirePostgreSql();

        $indexes = $this->indexDefinitions();

        $this->assertRequestIndex($indexes);
        $this->assertManualIndex($indexes);
    }

    public function test_migration_baru_memperbaiki_database_existing_yang_belum_memiliki_index_request(): void
    {
        $this->requirePostgreSql();
        DB::statement('DROP INDEX IF EXISTS '.self::REQUEST_INDEX);

        $this->assertArrayNotHasKey(self::REQUEST_INDEX, $this->indexDefinitions()->all());

        $migration = $this->migration();
        $this->runMigrationMethod($migration, 'up');
        $this->runMigrationMethod($migration, 'up');

        $indexes = $this->indexDefinitions();

        $this->assertRequestIndex($indexes);
        $this->assertSame(
            1,
            DB::table('pg_indexes')->where('indexname', self::REQUEST_INDEX)->count(),
            'Up yang diulang tidak boleh membuat index duplikat.',
        );
        $this->assertManualIndex($indexes);
    }

    public function test_down_hanya_menghapus_index_request_milik_migration_baru(): void
    {
        $this->requirePostgreSql();
        $migration = $this->migration();
        $this->runMigrationMethod($migration, 'up');

        $this->runMigrationMethod($migration, 'down');

        $indexes = $this->indexDefinitions();

        $this->assertArrayNotHasKey(self::REQUEST_INDEX, $indexes->all());
        $this->assertManualIndex($indexes);
        $this->assertTrue(Schema::hasColumn('leave_requests', 'leave_request_case_id'));
        $this->assertTrue(Schema::hasTable('leave_request_cases'));
    }

    private function migration(): Migration
    {
        $path = database_path('migrations/'.self::MIGRATION_FILE);

        $this->assertFileExists(
            $path,
            'Migration aditif wajib tersedia agar database existing menerima index baru.',
        );

        $migration = require $path;
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    private function runMigrationMethod(Migration $migration, string $method): void
    {
        $this->assertTrue(method_exists($migration, $method));

        (new ReflectionMethod($migration, $method))->invoke($migration);
    }

    /** @return Collection<string, string> */
    private function indexDefinitions(): Collection
    {
        return DB::table('pg_indexes')
            ->whereIn('indexname', [self::REQUEST_INDEX, self::MANUAL_INDEX])
            ->pluck('indexdef', 'indexname');
    }

    /** @param Collection<string, string> $indexes */
    private function assertRequestIndex(Collection $indexes): void
    {
        $this->assertArrayHasKey(self::REQUEST_INDEX, $indexes->all());
        $this->assertStringContainsString(
            '(leave_request_case_id, status, tanggal_mulai, tanggal_selesai)',
            $indexes[self::REQUEST_INDEX],
        );
    }

    /** @param Collection<string, string> $indexes */
    private function assertManualIndex(Collection $indexes): void
    {
        $this->assertArrayHasKey(self::MANUAL_INDEX, $indexes->all());
        $definition = $indexes[self::MANUAL_INDEX];
        $this->assertStringContainsString('(leave_request_case_id, start_date, end_date)', $definition);
        $this->assertStringContainsString('source_type', $definition);
        $this->assertStringContainsString('manual_external', $definition);
        $this->assertStringContainsString('record_status', $definition);
        $this->assertStringContainsString('active', $definition);
        $this->assertStringContainsString('leave_request_case_id IS NOT NULL', $definition);
    }

    private function requirePostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Kontrak index migration ini hanya dibuktikan pada PostgreSQL.');
        }
    }
}
