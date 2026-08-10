<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\EwsConfig;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use App\Services\Employees\TmtCalculatorService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TmtCalculatorMilestoneInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);

        // Set default config
        EwsConfig::updateOrCreate(['key' => 'pangkat_required_years'], ['value' => '4']);
        EwsConfig::updateOrCreate(['key' => 'kgb_required_years'], ['value' => '2']);
    }

    public function test_invalidates_pangkat_milestone_when_rank_history_deleted(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        // Create rank history and sync
        $rankHistory = RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => now()->subYears(3)->toDateString(),
            'golongan' => 'III/a',
            'pangkat' => 'Penata Muda',
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify milestone created
        $this->assertDatabaseHas('employee_milestones', [
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_KENAIKAN_PANGKAT,
            'is_active' => true,
        ]);

        // Delete rank history and resync
        $rankHistory->delete();
        $employee->refresh();
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify milestone invalidated
        $this->assertDatabaseHas('employee_milestones', [
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_KENAIKAN_PANGKAT,
            'is_active' => false,
        ]);
    }

    public function test_invalidates_kgb_milestone_when_salary_history_deleted(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        // Create salary history and sync
        $salaryHistory = SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => now()->subYears(1)->toDateString(),
            'gaji_pokok' => 3000000,
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify milestone created
        $this->assertDatabaseHas('employee_milestones', [
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_KGB,
            'is_active' => true,
        ]);

        // Delete salary history and resync
        $salaryHistory->delete();
        $employee->refresh();
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify milestone invalidated
        $this->assertDatabaseHas('employee_milestones', [
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_KGB,
            'is_active' => false,
        ]);
    }

    public function test_invalidates_old_satyalancana_milestones_when_tmt_changes(): void
    {
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'is_satyalancana_eligible' => true,
        ]);

        // Create first appointment and sync
        $appointment = Appointment::create([
            'employee_id' => $employee->id,
            'tmt_pengangkatan' => '2015-01-01',
            'jenis_pengangkatan' => 'PNS',
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify 3 satyalancana milestones created (10, 20, 30 years)
        $oldMilestones = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->where('is_active', true)
            ->get();

        $this->assertCount(3, $oldMilestones);
        $satyalancana10 = $oldMilestones->first(fn ($m) => $m->metadata['satyalancana_years'] == 10);
        $satyalancana20 = $oldMilestones->first(fn ($m) => $m->metadata['satyalancana_years'] == 20);
        $satyalancana30 = $oldMilestones->first(fn ($m) => $m->metadata['satyalancana_years'] == 30);

        $this->assertEquals('2025-01-01', $satyalancana10->milestone_date->toDateString());
        $this->assertEquals('2035-01-01', $satyalancana20->milestone_date->toDateString());
        $this->assertEquals('2045-01-01', $satyalancana30->milestone_date->toDateString());

        // Update TMT pengangkatan and resync
        $appointment->update(['tmt_pengangkatan' => '2016-06-01']); // Changed!
        $employee->refresh();
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify old milestones invalidated
        $invalidatedMilestones = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->where('is_active', false)
            ->get();

        $this->assertCount(3, $invalidatedMilestones, 'Old Satyalancana milestones should be invalidated');

        // Verify new milestones created with correct dates
        $newMilestones = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->where('is_active', true)
            ->get();

        $this->assertCount(3, $newMilestones);
        $newSatyalancana10 = $newMilestones->first(fn ($m) => $m->metadata['satyalancana_years'] == 10);
        $newSatyalancana20 = $newMilestones->first(fn ($m) => $m->metadata['satyalancana_years'] == 20);
        $newSatyalancana30 = $newMilestones->first(fn ($m) => $m->metadata['satyalancana_years'] == 30);

        $this->assertEquals('2026-06-01', $newSatyalancana10->milestone_date->toDateString());
        $this->assertEquals('2036-06-01', $newSatyalancana20->milestone_date->toDateString());
        $this->assertEquals('2046-06-01', $newSatyalancana30->milestone_date->toDateString());

        // Verify total: 3 old (inactive) + 3 new (active) = 6 records
        $totalMilestones = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->count();

        $this->assertEquals(6, $totalMilestones);
    }

    public function test_invalidates_all_satyalancana_when_appointments_deleted(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        // Create appointment and sync
        $appointment = Appointment::create([
            'employee_id' => $employee->id,
            'tmt_pengangkatan' => '2015-01-01',
            'jenis_pengangkatan' => 'PNS',
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify milestones created
        $this->assertEquals(3, EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->where('is_active', true)
            ->count());

        // Delete appointment and resync
        $appointment->delete();
        $employee->refresh();
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify all satyalancana milestones invalidated
        $this->assertEquals(0, EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->where('is_active', true)
            ->count());

        $this->assertEquals(3, EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->where('is_active', false)
            ->count());
    }

    public function test_invalidates_pppk_contract_milestone_when_tanggal_akhir_kontrak_cleared(): void
    {
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_akhir_kontrak' => now()->addYears(2)->toDateString(),
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify milestone created
        $this->assertDatabaseHas('employee_milestones', [
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_PPPK_CONTRACT_END,
            'is_active' => true,
        ]);

        // Clear tanggal_akhir_kontrak and resync
        $employee->update(['tanggal_akhir_kontrak' => null]);
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify milestone invalidated
        $this->assertDatabaseHas('employee_milestones', [
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_PPPK_CONTRACT_END,
            'is_active' => false,
        ]);
    }

    public function test_updates_milestone_when_source_data_changes(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        // Create initial rank history
        $rankHistory = RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2020-01-01',
            'golongan' => 'III/a',
            'pangkat' => 'Penata Muda',
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $oldMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->first();

        $this->assertEquals('2024-01-01', $oldMilestone->milestone_date->toDateString());
        $oldMilestoneId = $oldMilestone->id;

        // Update TMT pangkat (simulate correction)
        $rankHistory->update(['tmt_pangkat' => '2021-06-01']);
        $employee->refresh();
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify same record updated (not new record created)
        $updatedMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->where('is_active', true)
            ->first();

        $this->assertEquals($oldMilestoneId, $updatedMilestone->id, 'Should update same record');
        $this->assertEquals('2025-06-01', $updatedMilestone->milestone_date->toDateString());
        $this->assertEquals('2021-06-01', $updatedMilestone->metadata['tmt_pangkat']);

        // Verify only one active milestone exists
        $this->assertEquals(1, EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->where('is_active', true)
            ->count());
    }

    public function test_reconciliation_handles_multiple_milestone_types_independently(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        // Create all source data
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => now()->subYears(3)->toDateString(),
            'golongan' => 'III/a',
            'pangkat' => 'Penata Muda',
        ]);

        SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => now()->subYears(1)->toDateString(),
            'gaji_pokok' => 3000000,
        ]);

        Appointment::create([
            'employee_id' => $employee->id,
            'tmt_pengangkatan' => '2015-01-01',
            'jenis_pengangkatan' => 'PNS',
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify all milestones created
        $this->assertTrue(EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->where('is_active', true)
            ->exists());

        $this->assertTrue(EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KGB)
            ->where('is_active', true)
            ->exists());

        $this->assertEquals(3, EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->where('is_active', true)
            ->count());

        // Delete only Satyalancana source (appointments)
        Appointment::where('employee_id', $employee->id)->delete();
        $employee->refresh();
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify only Satyalancana invalidated, others remain active
        $this->assertTrue(EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->where('is_active', true)
            ->exists(), 'Pangkat should still be active');

        $this->assertTrue(EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KGB)
            ->where('is_active', true)
            ->exists(), 'KGB should still be active');

        $this->assertEquals(0, EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->where('is_active', true)
            ->count(), 'Satyalancana should be invalidated');
    }
}
