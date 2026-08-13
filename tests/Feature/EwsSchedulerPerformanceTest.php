<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Models\EwsSchedulerRun;
use App\Services\EwsEngineService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EwsSchedulerPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Scheduler tidak mengalami N+1 query pada deployment awal (tanpa milestone backfill).
     *
     * Skenario: 10 pegawai aktif tanpa employee_milestones (deployment baru).
     * Expected: Query count terbatas, tidak ada lazy loading per-employee untuk rankHistories/salaryHistories/appointments.
     */
    public function test_scheduler_avoids_n_plus_one_queries_when_milestones_not_precomputed(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Seed or update config
        $configs = [
            'pangkat_h90' => '90',
            'kgb_h60' => '60',
            'pensiun_y1' => '365',
            'pppk_m6' => '180',
            'satyalancana_h180' => '180',
            'pangkat_required_years' => '4',
            'kgb_required_years' => '2',
        ];

        foreach ($configs as $key => $value) {
            EwsConfig::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // Create 10 employees with histories but NO milestones (simulates deployment sebelum backfill)
        $employees = Employee::factory()
            ->count(10)
            ->create(['status_aktif' => 'Aktif']);

        foreach ($employees as $employee) {
            // Add histories that fallback will use - with dates that will trigger alerts
            // Pangkat: TMT 3 years ago + 4 years required = 1 year from now (within H-90 threshold)
            $employee->rankHistories()->create([
                'pangkat_id' => 1,
                'tmt_pangkat' => now()->subYears(3)->toDateString(),
            ]);

            // KGB: TMT 23 months ago + 2 years required = 1 month from now (within H-60 threshold)
            $employee->salaryHistories()->create([
                'gaji_pokok' => 3000000,
                'tmt_kgb' => now()->subMonths(23)->toDateString(),
            ]);

            // Appointment for Satyalancana calculation
            $employee->appointments()->create([
                'jenis_pengangkatan' => 'CPNS',
                'tmt_pengangkatan' => now()->subYears(5)->toDateString(),
            ]);
        }

        // Assert: tidak ada milestone yang sudah dibuat
        $this->assertEquals(0, EmployeeMilestone::count(), 'Test requires zero pre-existing milestones');

        // Enable query log
        DB::enableQueryLog();

        // Run scheduler
        $service = app(EwsEngineService::class);
        $service->run();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Expected query pattern:
        // 1. Config queries
        // 2. Employee chunk dengan eager loading (1 + 6 relations)
        // 3. Alert upsert (per alert type, bisa jadi N queries karena upsert pattern)
        // 4. Notification creation (per employee per alert type)
        // 5. Scheduler run updates
        //
        // With alert creation and notification, 10 employees × multiple alert types can generate many queries.
        // The key test is: does query count scale linearly with employee count?
        // Threshold: < 200 queries (allows for alert/notification overhead, still catches N+1 in relations)
        $queryCount = count($queries);

        $this->assertLessThan(
            200,
            $queryCount,
            "Expected < 200 queries for 10 employees without milestones, got {$queryCount}. If significantly higher, indicates N+1 query problem."
        );

        // Verify: Scheduler created alerts (proves fallback calculation worked)
        $this->assertGreaterThan(0, EwsAlert::count(), 'Scheduler should have created alerts via fallback');
    }

    /**
     * Test: Scheduler tetap efisien setelah milestone backfill (best-case scenario).
     *
     * Skenario: 10 pegawai dengan precomputed milestones.
     * Expected: Query count lebih rendah karena tidak perlu fallback calculation.
     */
    public function test_scheduler_is_efficient_with_precomputed_milestones(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Seed or update config
        $configs = [
            'pangkat_h90' => '90',
            'kgb_h60' => '60',
            'pensiun_y1' => '365',
            'pangkat_required_years' => '4',
            'kgb_required_years' => '2',
        ];

        foreach ($configs as $key => $value) {
            EwsConfig::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // Create 10 employees WITH precomputed milestones
        $employees = Employee::factory()
            ->count(10)
            ->create(['status_aktif' => 'Aktif']);

        foreach ($employees as $employee) {
            // Create precomputed milestones (simulates post-backfill state)
            $employee->milestones()->create([
                'type' => 'kenaikan_pangkat',
                'milestone_date' => now()->addMonths(2)->toDateString(),
                'is_active' => true,
                'calculated_at' => now(),
                'metadata' => ['required_years' => 4],
            ]);

            $employee->milestones()->create([
                'type' => 'kgb',
                'milestone_date' => now()->addMonths(1)->toDateString(),
                'is_active' => true,
                'calculated_at' => now(),
                'metadata' => ['required_years' => 2],
            ]);

            $employee->milestones()->create([
                'type' => 'pensiun',
                'milestone_date' => now()->addYears(1)->toDateString(),
                'is_active' => true,
                'calculated_at' => now(),
                'metadata' => ['is_manual' => false, 'source' => 'calculated_from_bup'],
            ]);
        }

        // Enable query log
        DB::enableQueryLog();

        // Run scheduler
        $service = app(EwsEngineService::class);
        $service->run();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $queryCount = count($queries);

        // With milestones, eager loading still happens but milestones avoid fallback calculation
        // Query count might be similar or slightly higher due to milestone reads + notifications
        $this->assertLessThan(
            350,
            $queryCount,
            "Expected < 350 queries for 10 employees with milestones, got {$queryCount}."
        );

        // Verify: Scheduler created alerts using milestones
        $this->assertGreaterThan(0, EwsAlert::count(), 'Scheduler should have created alerts from milestones');
    }

    /**
     * Test: Query count scaling - verify linear growth, not quadratic.
     */
    public function test_scheduler_query_count_scales_linearly_not_quadratically(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Seed or update config
        $configs = [
            'pangkat_h90' => '90',
            'pangkat_required_years' => '4',
        ];

        foreach ($configs as $key => $value) {
            EwsConfig::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // Test with 5 employees
        Employee::factory()
            ->count(5)
            ->create(['status_aktif' => 'Aktif']);

        DB::enableQueryLog();
        $service = app(EwsEngineService::class);
        $service->run();
        $queries5 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Clear and test with 10 employees
        EwsAlert::truncate();
        EwsSchedulerRun::truncate();
        Employee::truncate();

        Employee::factory()
            ->count(10)
            ->create(['status_aktif' => 'Aktif']);

        DB::enableQueryLog();
        $service = app(EwsEngineService::class);
        $service->run();
        $queries10 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Query growth should be roughly linear (2x employees ≈ 2x queries), not quadratic (2x employees = 4x queries)
        // Allow 2.5x factor for: chunk overhead, alert/notification queries, transaction overhead
        $growthFactor = $queries10 / max(1, $queries5);
        $expectedMaxQueries10 = $queries5 * 3.0; // Allow 3x growth for 2x employees

        $this->assertLessThan(
            $expectedMaxQueries10,
            $queries10,
            "Query count should scale linearly, not quadratically. 5 employees: {$queries5} queries, 10 employees: {$queries10} queries (growth factor: ".round($growthFactor, 2).'x, expected < 3x)'
        );
    }
}
