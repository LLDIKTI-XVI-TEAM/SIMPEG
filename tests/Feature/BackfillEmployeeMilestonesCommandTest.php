<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillEmployeeMilestonesCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Memastikan rekonsiliasi membuat milestone pegawai lama agar scheduler tidak selalu menghitung ulang. */
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

    public function test_backfill_command_is_idempotent_for_existing_milestones(): void
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

        $this->artisan('milestone:backfill', ['--no-interaction' => true])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        $milestoneCountAfterFirstRun = EmployeeMilestone::where('employee_id', $employee->id)->count();

        $this->artisan('milestone:backfill', ['--no-interaction' => true])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        $this->assertSame(
            $milestoneCountAfterFirstRun,
            EmployeeMilestone::where('employee_id', $employee->id)->count()
        );

        $duplicateIdentities = EmployeeMilestone::where('employee_id', $employee->id)
            ->selectRaw('type, milestone_date, COUNT(*) AS aggregate')
            ->groupBy('type', 'milestone_date')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->assertSame(0, $duplicateIdentities);
    }

    public function test_backfill_command_reconciles_existing_milestone_by_default(): void
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

        $oldMilestone = EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => 'kenaikan_pangkat',
            'milestone_date' => '2023-01-01',
            'calculated_at' => now()->subDays(30),
            'is_active' => true,
            'metadata' => ['required_years' => 3],
        ]);

        $oldMilestoneId = $oldMilestone->id;
        $oldCalculatedAt = $oldMilestone->calculated_at;

        $this->artisan('milestone:backfill', ['--no-interaction' => true])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        $updatedMilestone = EmployeeMilestone::find($oldMilestoneId);

        $this->assertNotNull($updatedMilestone, 'Milestone should still exist (updated, not replaced)');
        $this->assertTrue($updatedMilestone->is_active, 'Milestone should be active');
        $this->assertEquals(4, $updatedMilestone->metadata['required_years'], 'Should use current config (4 years)');
        $this->assertTrue(
            $updatedMilestone->calculated_at->isAfter($oldCalculatedAt),
            'calculated_at should be refreshed'
        );
    }

    /** Memastikan opsi only-active tidak membuat milestone untuk pegawai tidak aktif. */
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

    /** Memastikan rekonsiliasi per batch tetap memproses seluruh pegawai. */
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

    /** Memastikan pembatalan tidak membuat milestone parsial. */
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

    /** Memastikan milestone nonaktif tidak membuat pegawai terlewati saat rekonsiliasi. */
    public function test_backfill_command_syncs_employees_with_only_inactive_milestones(): void
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

        // Create inactive milestone (invalidated by config change)
        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => 'kenaikan_pangkat',
            'milestone_date' => '2024-01-01',
            'calculated_at' => now()->subDays(30),
            'is_active' => false, // ← Inactive
            'metadata' => ['required_years' => 3],
        ]);

        // Milestone yang tidak aktif tidak boleh membuat pegawai dilewati.
        $this->artisan('milestone:backfill', ['--no-interaction' => true])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        // Verify: Employee was NOT skipped (because only inactive milestones exist)
        // New active milestones should be created
        $activeMilestones = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('is_active', true)
            ->count();

        $this->assertGreaterThan(0, $activeMilestones, 'Employee with only inactive milestones should be synced');
    }

    public function test_backfill_command_completes_partial_satyalancana_milestones(): void
    {
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
        ]);

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2015-01-01',
        ]);

        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_SATYALANCANA,
            'milestone_date' => '2025-01-01',
            'calculated_at' => now(),
            'is_active' => true,
            'metadata' => [
                'tmt_pengangkatan' => '2015-01-01',
                'satyalancana_years' => 10,
                'years_of_service' => 10,
            ],
        ]);

        $this->artisan('milestone:backfill', [
            '--only-active' => true,
            '--no-interaction' => true,
        ])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        $activeSatyalancana = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->where('is_active', true)
            ->get();

        $this->assertCount(3, $activeSatyalancana);
        $this->assertSame(
            [10 => 1, 20 => 1, 30 => 1],
            $activeSatyalancana
                ->countBy(fn (EmployeeMilestone $milestone): int => (int) $milestone->metadata['satyalancana_years'])
                ->sortKeys()
                ->all()
        );
    }

    /** Nilai chunk nol tidak boleh diteruskan ke chunkById sebagai eksekusi semu. */
    public function test_backfill_command_rejects_zero_chunk_size(): void
    {
        $this->artisan('milestone:backfill', ['--chunk' => 0, '--no-interaction' => true])
            ->expectsOutput('Chunk size must be at least 1.')
            ->assertExitCode(Command::INVALID);
    }

    /** Nilai chunk negatif harus ditolak sebelum query pegawai dijalankan. */
    public function test_backfill_command_rejects_negative_chunk_size(): void
    {
        $this->artisan('milestone:backfill', ['--chunk' => -10, '--no-interaction' => true])
            ->expectsOutput('Chunk size must be at least 1.')
            ->assertExitCode(Command::INVALID);
    }

    /** Backfill biasa menjaga nilai pensiun lama yang provenance-nya belum dapat diverifikasi. */
    public function test_backfill_preserves_legacy_pension_date_by_default(): void
    {
        $employee = $this->employeeWithLegacyPensionDate();

        $this->artisan('milestone:backfill', ['--no-interaction' => true])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        $employee->refresh();
        $milestone = EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->sole();

        $this->assertSame('2035-06-30', $employee->tanggal_pensiun?->toDateString());
        $this->assertSame('2035-06-30', $milestone->milestone_date->toDateString());
        $this->assertSame('legacy_unverified', $milestone->metadata['source']);
        $this->assertTrue($milestone->metadata['is_manual']);
    }

    /** Operator dapat menghitung ulang nilai legacy secara eksplisit tanpa menebak kesamaan tanggal BUP. */
    public function test_backfill_can_explicitly_recalculate_legacy_pension_date(): void
    {
        $employee = $this->employeeWithLegacyPensionDate();

        $this->artisan('milestone:backfill', ['--no-interaction' => true])
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        $this->assertSame('legacy_unverified', EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->sole()
            ->metadata['source']);

        $this->artisan('milestone:backfill', [
            '--recalculate-legacy-pension' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('WARNING: Legacy pension dates will be recalculated from current BUP sources.')
            ->expectsQuestion('Do you want to proceed with the backfill?', true)
            ->assertSuccessful();

        $employee->refresh();
        $milestone = EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->sole();

        $this->assertSame('2028-01-15', $employee->tanggal_pensiun?->toDateString());
        $this->assertSame('2028-01-15', $milestone->milestone_date->toDateString());
        $this->assertSame('calculated_from_bup', $milestone->metadata['source']);
        $this->assertFalse($milestone->metadata['is_manual']);
    }

    private function employeeWithLegacyPensionDate(): Employee
    {
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1970-01-15',
            'tanggal_pensiun' => '2035-06-30',
        ]);
        $jabatan = RefJabatan::create([
            'nama' => 'Jabatan Uji Backfill Legacy',
            'default_bup' => 58,
            'is_active' => true,
        ]);
        $employee->positionHistories()->create([
            'jabatan_id' => $jabatan->id,
            'nama_jabatan' => $jabatan->nama,
            'tmt_jabatan' => '2020-01-01',
            'is_latest' => true,
        ]);

        return $employee;
    }
}
