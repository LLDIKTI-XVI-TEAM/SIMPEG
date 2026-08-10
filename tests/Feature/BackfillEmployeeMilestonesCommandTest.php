<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\RefGolongan;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillEmployeeMilestonesCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: milestone:backfill command creates milestones for employees without them.
     *
     * US-5.5 AC-5: On deployment, existing employees don't have milestones until updated.
     * Scheduler has fallback but causes N+1 queries. Backfill command should create
     * milestones for all existing employees.
     */
    public function test_backfill_command_creates_milestones_for_employees_without_them(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Create employees without milestones (simulating pre-deployment state)
        $employee1 = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1980-01-01',
        ]);

        $employee2 = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1985-06-15',
        ]);

        // Create rank histories to enable rank milestones
        $employee1->rankHistories()->create([
            'golongan_id' => RefGolongan::first()->id,
            'tmt_pangkat' => '2020-01-01',
            'no_sk' => 'SK-001',
            'tanggal_sk' => '2019-12-15',
            'is_latest' => true,
        ]);

        $employee2->rankHistories()->create([
            'golongan_id' => RefGolongan::first()->id,
            'tmt_pangkat' => '2021-06-01',
            'no_sk' => 'SK-002',
            'tanggal_sk' => '2021-05-15',
            'is_latest' => true,
        ]);

        // Verify: No milestones exist
        $this->assertEquals(0, EmployeeMilestone::count(), 'Should have no milestones before backfill');

        // Run backfill command
        $this->artisan('milestone:backfill', ['--no-interaction' => true])
            ->expectsOutput('Starting employee milestone backfill...')
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        // Verify: Milestones created for both employees
        $employee1Milestones = EmployeeMilestone::where('employee_id', $employee1->id)
            ->where('is_active', true)
            ->count();

        $employee2Milestones = EmployeeMilestone::where('employee_id', $employee2->id)
            ->where('is_active', true)
            ->count();

        $this->assertGreaterThan(0, $employee1Milestones, 'Employee 1 should have milestones after backfill');
        $this->assertGreaterThan(0, $employee2Milestones, 'Employee 2 should have milestones after backfill');
    }

    /**
     * Test: backfill command skips employees that already have milestones.
     */
    public function test_backfill_command_skips_employees_with_existing_milestones(): void
    {
        $this->seed(ReferenceSeeder::class);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1980-01-01',
        ]);

        $employee->rankHistories()->create([
            'golongan_id' => RefGolongan::first()->id,
            'tmt_pangkat' => '2020-01-01',
            'no_sk' => 'SK-001',
            'tanggal_sk' => '2019-12-15',
            'is_latest' => true,
        ]);

        // Pre-create a milestone
        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => 'kenaikan_pangkat',
            'milestone_date' => '2024-01-01',
            'is_active' => true,
            'metadata' => ['required_years' => 4],
        ]);

        $initialMilestoneCount = EmployeeMilestone::where('employee_id', $employee->id)->count();

        // Run backfill without --force
        $this->artisan('milestone:backfill', ['--no-interaction' => true])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        // Verify: No new milestones created (skipped)
        $finalMilestoneCount = EmployeeMilestone::where('employee_id', $employee->id)->count();
        $this->assertEquals($initialMilestoneCount, $finalMilestoneCount, 'Should skip employee with existing milestones');
    }

    /**
     * Test: backfill command with --force flag recreates milestones.
     */
    public function test_backfill_command_with_force_recreates_milestones(): void
    {
        $this->seed(ReferenceSeeder::class);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1980-01-01',
        ]);

        $employee->rankHistories()->create([
            'golongan_id' => RefGolongan::first()->id,
            'tmt_pangkat' => '2020-01-01',
            'no_sk' => 'SK-001',
            'tanggal_sk' => '2019-12-15',
            'is_latest' => true,
        ]);

        // Pre-create an old milestone
        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => 'kenaikan_pangkat',
            'milestone_date' => '2024-01-01',
            'is_active' => true,
            'metadata' => ['required_years' => 3], // ← Old config (wrong)
        ]);

        // Run backfill with --force
        $this->artisan('milestone:backfill', ['--force' => true, '--no-interaction' => true])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        // Verify: Old milestone invalidated, new one created with correct config
        $oldMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('metadata->required_years', 3)
            ->first();

        $newMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('metadata->required_years', 4)  // Current config
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($oldMilestone, 'Old milestone should exist');
        $this->assertFalse($oldMilestone->is_active, 'Old milestone should be invalidated');
        $this->assertNotNull($newMilestone, 'New milestone with correct config should be created');
    }

    /**
     * Test: backfill command with --only-active flag processes only active employees.
     */
    public function test_backfill_command_with_only_active_flag(): void
    {
        $this->seed(ReferenceSeeder::class);

        $activeEmployee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1980-01-01',
        ]);

        $inactiveEmployee = Employee::factory()->create([
            'status_aktif' => 'Nonaktif',
            'tanggal_lahir' => '1985-06-15',
        ]);

        $activeEmployee->rankHistories()->create([
            'golongan_id' => RefGolongan::first()->id,
            'tmt_pangkat' => '2020-01-01',
            'no_sk' => 'SK-001',
            'tanggal_sk' => '2019-12-15',
            'is_latest' => true,
        ]);

        $inactiveEmployee->rankHistories()->create([
            'golongan_id' => RefGolongan::first()->id,
            'tmt_pangkat' => '2021-06-01',
            'no_sk' => 'SK-002',
            'tanggal_sk' => '2021-05-15',
            'is_latest' => true,
        ]);

        // Run backfill with --only-active
        $this->artisan('milestone:backfill', ['--only-active' => true, '--no-interaction' => true])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        // Verify: Only active employee has milestones
        $activeMilestones = EmployeeMilestone::where('employee_id', $activeEmployee->id)->count();
        $inactiveMilestones = EmployeeMilestone::where('employee_id', $inactiveEmployee->id)->count();

        $this->assertGreaterThan(0, $activeMilestones, 'Active employee should have milestones');
        $this->assertEquals(0, $inactiveMilestones, 'Inactive employee should NOT have milestones');
    }

    /**
     * Test: backfill command with custom chunk size.
     */
    public function test_backfill_command_with_custom_chunk_size(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Create 5 employees
        $employees = Employee::factory()->count(5)->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1980-01-01',
        ]);

        foreach ($employees as $employee) {
            $employee->rankHistories()->create([
                'golongan_id' => RefGolongan::first()->id,
                'tmt_pangkat' => '2020-01-01',
                'no_sk' => 'SK-001',
                'tanggal_sk' => '2019-12-15',
                'is_latest' => true,
            ]);
        }

        // Run with chunk size 2 (should process in 3 chunks: 2+2+1)
        $this->artisan('milestone:backfill', ['--chunk' => 2, '--no-interaction' => true])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        // Verify: All employees have milestones
        foreach ($employees as $employee) {
            $milestoneCount = EmployeeMilestone::where('employee_id', $employee->id)->count();
            $this->assertGreaterThan(0, $milestoneCount, "Employee {$employee->id} should have milestones");
        }
    }

    /**
     * Test: backfill command can be cancelled.
     */
    public function test_backfill_command_can_be_cancelled(): void
    {
        $this->seed(ReferenceSeeder::class);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1980-01-01',
        ]);

        $employee->rankHistories()->create([
            'golongan_id' => RefGolongan::first()->id,
            'tmt_pangkat' => '2020-01-01',
            'no_sk' => 'SK-001',
            'tanggal_sk' => '2019-12-15',
            'is_latest' => true,
        ]);

        // Cancel the backfill
        $this->artisan('milestone:backfill')
            ->expectsQuestion('Do you want to proceed with the backfill?', false)
            ->expectsOutput('Backfill cancelled.')
            ->assertSuccessful();

        // Verify: No milestones created
        $this->assertEquals(0, EmployeeMilestone::count(), 'Should have no milestones after cancelling');
    }
}
