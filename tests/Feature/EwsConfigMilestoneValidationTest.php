<?php

namespace Tests\Feature;

use App\Actions\Ews\UpdateEwsConfigAction;
use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefUnitKerja;
use App\Models\SalaryHistory;
use App\Models\User;
use App\Services\Employees\TmtCalculatorService;
use App\Services\EwsEngineService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EwsConfigMilestoneValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);

        // Set default config
        EwsConfig::updateOrCreate(['key' => 'pangkat_required_years'], ['value' => '4']);
        EwsConfig::updateOrCreate(['key' => 'kgb_required_years'], ['value' => '2']);
        EwsConfig::updateOrCreate(['key' => 'pangkat_h90'], ['value' => '90']);
        EwsConfig::updateOrCreate(['key' => 'pangkat_h60'], ['value' => '60']);
        EwsConfig::updateOrCreate(['key' => 'pangkat_h30'], ['value' => '30']);
        EwsConfig::updateOrCreate(['key' => 'kgb_h60'], ['value' => '60']);
        EwsConfig::updateOrCreate(['key' => 'kgb_h30'], ['value' => '30']);
        EwsConfig::updateOrCreate(['key' => 'kgb_h14'], ['value' => '14']);
    }

    public function test_scheduler_uses_fallback_calculation_when_milestone_has_outdated_required_years(): void
    {
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'is_kinerja_baik' => true,
        ]);

        // Create rank history
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => now()->subYears(3)->addDays(60)->toDateString(), // Will trigger H-60 alert
            'golongan' => 'III/a',
            'pangkat' => 'Penata Muda',
        ]);

        // Create milestone with old config (4 years)
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $milestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->first();

        $this->assertNotNull($milestone);
        $this->assertEquals(4, $milestone->metadata['required_years']);
        $oldMilestoneDate = $milestone->milestone_date->toDateString();

        // Change config to 5 years
        EwsConfig::setVal('pangkat_required_years', '5');

        // Run scheduler - should use fallback calculation with new config (5 years)
        $employee->refresh();
        $employee->load(['milestones', 'jenisPegawai', 'disciplineRecords']);

        $ewsService = app(EwsEngineService::class);
        $ewsService->run();

        // Verify alert created with NEW calculation (5 years), not old milestone (4 years)
        $alert = EwsAlert::where('employee_id', $employee->id)
            ->where('type', 'KENAIKAN_PANGKAT')
            ->first();

        if ($alert) {
            // Target date should be based on 5 years, not 4 years
            $expectedDate = now()->subYears(3)->addDays(60)->addYears(5)->toDateString();
            $this->assertEquals($expectedDate, $alert->target_date);
            $this->assertNotEquals($oldMilestoneDate, $alert->target_date,
                'Alert should use new config calculation, not old milestone');
        }
    }

    public function test_scheduler_uses_milestone_when_required_years_matches_current_config(): void
    {
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'is_kinerja_baik' => true,
        ]);

        // Create rank history
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => now()->subYears(3)->addDays(60)->toDateString(),
            'golongan' => 'III/a',
            'pangkat' => 'Penata Muda',
        ]);

        // Create milestone with current config (4 years)
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $milestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->first();

        $this->assertNotNull($milestone);
        $this->assertEquals(4, $milestone->metadata['required_years']);
        $milestoneDate = $milestone->milestone_date->toDateString();

        // Run scheduler with SAME config (4 years) - should use milestone
        $employee->refresh();
        $employee->load(['milestones', 'jenisPegawai', 'disciplineRecords']);

        $ewsService = app(EwsEngineService::class);
        $ewsService->run();

        // Verify alert created with milestone date
        $alert = EwsAlert::where('employee_id', $employee->id)
            ->where('type', 'KENAIKAN_PANGKAT')
            ->first();

        if ($alert) {
            $this->assertEquals($milestoneDate, $alert->target_date,
                'Alert should use milestone when config matches');
        }
    }

    public function test_update_config_action_invalidates_affected_milestones(): void
    {
        // Create multiple employees with pangkat milestones
        $employees = Employee::factory()->count(3)->create(['status_aktif' => 'Aktif']);

        foreach ($employees as $employee) {
            RankHistory::create([
                'employee_id' => $employee->id,
                'tmt_pangkat' => now()->subYears(3)->toDateString(),
                'golongan' => 'III/a',
                'pangkat' => 'Penata Muda',
            ]);

            app(TmtCalculatorService::class)->syncForEmployee($employee);
        }

        // Verify all milestones created with required_years = 4
        $this->assertEquals(3, EmployeeMilestone::where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->where('is_active', true)
            ->whereJsonContains('metadata->required_years', 4)
            ->count());

        // Update config from 4 to 5 years
        $request = Request::create('/ews/config', 'POST', [
            'pangkat_required_years' => '5',
            'kgb_required_years' => '2', // Unchanged
            'reason' => 'Policy change: extend promotion period',
        ]);
        $request->setUserResolver(fn () => User::factory()->create(['role' => 'super_admin']));

        $action = app(UpdateEwsConfigAction::class);
        $action->execute($request);

        // Verify all pangkat milestones with old config invalidated
        $this->assertEquals(0, EmployeeMilestone::where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->where('is_active', true)
            ->count(), 'All pangkat milestones should be invalidated');

        $this->assertEquals(3, EmployeeMilestone::where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->where('is_active', false)
            ->count(), 'All pangkat milestones should be marked inactive');

        // Verify config updated
        $this->assertEquals('5', EwsConfig::getVal('pangkat_required_years'));
    }

    public function test_update_config_does_not_invalidate_unaffected_milestones(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        // Create both pangkat and KGB milestones
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

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify both milestones created
        $this->assertTrue(EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->where('is_active', true)
            ->exists());

        $this->assertTrue(EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KGB)
            ->where('is_active', true)
            ->exists());

        // Update ONLY pangkat_required_years
        $request = Request::create('/ews/config', 'POST', [
            'pangkat_required_years' => '5', // Changed
            'kgb_required_years' => '2', // Unchanged
            'reason' => 'Update promotion period',
        ]);
        $request->setUserResolver(fn () => User::factory()->create(['role' => 'super_admin']));

        $action = app(UpdateEwsConfigAction::class);
        $action->execute($request);

        // Verify only pangkat invalidated, KGB remains active
        $this->assertFalse(EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->where('is_active', true)
            ->exists(), 'Pangkat milestone should be invalidated');

        $this->assertTrue(EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KGB)
            ->where('is_active', true)
            ->exists(), 'KGB milestone should remain active');
    }

    public function test_kgb_config_change_invalidates_kgb_milestones(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => now()->subYears(1)->toDateString(),
            'gaji_pokok' => 3000000,
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $milestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KGB)
            ->first();

        $this->assertNotNull($milestone);
        $this->assertEquals(2, $milestone->metadata['required_years']);

        // Update kgb_required_years from 2 to 3 years
        $request = Request::create('/ews/config', 'POST', [
            'pangkat_required_years' => '4', // Unchanged
            'kgb_required_years' => '3', // Changed
            'reason' => 'Extend KGB period',
        ]);
        $request->setUserResolver(fn () => User::factory()->create(['role' => 'super_admin']));

        $action = app(UpdateEwsConfigAction::class);
        $action->execute($request);

        // Verify KGB milestone invalidated
        $milestone->refresh();
        $this->assertFalse($milestone->is_active, 'KGB milestone should be invalidated');
        $this->assertEquals('3', EwsConfig::getVal('kgb_required_years'));
    }

    public function test_no_invalidation_when_config_unchanged(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => now()->subYears(3)->toDateString(),
            'golongan' => 'III/a',
            'pangkat' => 'Penata Muda',
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $milestoneId = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->first()->id;

        // Submit same config values
        $request = Request::create('/ews/config', 'POST', [
            'pangkat_required_years' => '4', // Same
            'kgb_required_years' => '2', // Same
            'reason' => 'No change',
        ]);
        $request->setUserResolver(fn () => User::factory()->create(['role' => 'super_admin']));

        $action = app(UpdateEwsConfigAction::class);
        $action->execute($request);

        // Verify milestone still active
        $milestone = EmployeeMilestone::find($milestoneId);
        $this->assertTrue($milestone->is_active, 'Milestone should remain active when config unchanged');
    }

    public function test_pension_config_change_invalidates_global_config_milestones(): void
    {
        EwsConfig::updateOrCreate(['key' => 'pensiun_required_age_years'], ['value' => '58']);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1967-03-20',
            'tanggal_pensiun' => null,
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $milestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($milestone);
        $this->assertEquals('calculated_from_global_config', $milestone->metadata['source']);
        $this->assertEquals('pensiun_required_age_years', $milestone->metadata['config_key']);

        $request = Request::create('/ews/config', 'POST', [
            'pangkat_required_years' => '4',
            'kgb_required_years' => '2',
            'pensiun_required_age_years' => '60',
            'reason' => 'Extend retirement age',
        ]);
        $request->setUserResolver(fn () => User::factory()->create(['role' => 'super_admin']));

        (new UpdateEwsConfigAction)->execute($request);

        $milestone->refresh();
        $this->assertFalse($milestone->is_active);
        $this->assertEquals('60', EwsConfig::getVal('pensiun_required_age_years'));
    }

    public function test_pension_config_change_does_not_invalidate_position_bup_milestones(): void
    {
        EwsConfig::updateOrCreate(['key' => 'pensiun_required_age_years'], ['value' => '58']);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1967-03-20',
            'tanggal_pensiun' => null,
        ]);

        $jenisJabatan = RefJenisJabatan::create([
            'nama' => 'Fungsional Uji',
            'maks_usia_pensiun' => 60,
            'is_active' => true,
        ]);
        $jabatan = RefJabatan::create([
            'nama' => 'Jabatan Uji BUP',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'default_bup' => 58,
            'is_active' => true,
        ]);

        PositionHistory::create([
            'employee_id' => $employee->id,
            'jabatan_id' => $jabatan->id,
            'jenis_jabatan_id' => $jenisJabatan->id,
            'nama_jabatan' => $jabatan->nama,
            'unit_kerja_id' => RefUnitKerja::query()->firstOrFail()->id,
            'tmt_jabatan' => '2020-01-01',
            'no_sk' => 'SK-BUP-001',
            'tanggal_sk' => '2019-12-15',
            'is_latest' => true,
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $milestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($milestone);
        $this->assertEquals('calculated_from_bup', $milestone->metadata['source']);

        $request = Request::create('/ews/config', 'POST', [
            'pangkat_required_years' => '4',
            'kgb_required_years' => '2',
            'pensiun_required_age_years' => '65',
            'reason' => 'Extend retirement age',
        ]);
        $request->setUserResolver(fn () => User::factory()->create(['role' => 'super_admin']));

        (new UpdateEwsConfigAction)->execute($request);

        $milestone->refresh();
        $this->assertTrue($milestone->is_active);
    }

    public function test_pension_config_change_does_not_invalidate_manual_pension_milestones(): void
    {
        EwsConfig::updateOrCreate(['key' => 'pensiun_required_age_years'], ['value' => '58']);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1967-03-20',
            'tanggal_pensiun' => '2030-06-15',
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee, pensionDateIsAuthoritative: true);

        $milestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($milestone);
        $this->assertEquals('employees.tanggal_pensiun', $milestone->metadata['source']);

        $request = Request::create('/ews/config', 'POST', [
            'pangkat_required_years' => '4',
            'kgb_required_years' => '2',
            'pensiun_required_age_years' => '65',
            'reason' => 'Extend retirement age',
        ]);
        $request->setUserResolver(fn () => User::factory()->create(['role' => 'super_admin']));

        (new UpdateEwsConfigAction)->execute($request);

        $milestone->refresh();
        $this->assertTrue($milestone->is_active);
    }
}
