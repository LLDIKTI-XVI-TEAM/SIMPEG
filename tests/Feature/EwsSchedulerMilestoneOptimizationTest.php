<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\EwsConfig;
use App\Models\RankHistory;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\SalaryHistory;
use App\Services\EwsEngineService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EwsSchedulerMilestoneOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);

        // Set default config
        EwsConfig::updateOrCreate(['key' => 'pangkat_h90'], ['value' => '90']);
        EwsConfig::updateOrCreate(['key' => 'kgb_h60'], ['value' => '60']);
        EwsConfig::updateOrCreate(['key' => 'pensiun_y1'], ['value' => '365']);
        EwsConfig::updateOrCreate(['key' => 'pppk_m6'], ['value' => '180']);
        EwsConfig::updateOrCreate(['key' => 'satyalancana_h180'], ['value' => '180']);
        EwsConfig::updateOrCreate(['key' => 'pangkat_required_years'], ['value' => '4']);
        EwsConfig::updateOrCreate(['key' => 'kgb_required_years'], ['value' => '2']);
        EwsConfig::updateOrCreate(['key' => 'pppk_contract_years'], ['value' => '4']);
        EwsConfig::updateOrCreate(['key' => 'satyalancana_years_1'], ['value' => '10']);
        EwsConfig::updateOrCreate(['key' => 'satyalancana_years_2'], ['value' => '20']);
        EwsConfig::updateOrCreate(['key' => 'satyalancana_years_3'], ['value' => '30']);
    }

    public function test_scheduler_uses_precomputed_milestones_when_available(): void
    {
        // Setup: Create employee with precomputed milestones
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'is_kinerja_baik' => true,
            'is_satyalancana_eligible' => true,
        ]);

        // Precompute milestones
        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_KENAIKAN_PANGKAT,
            'milestone_date' => now()->addDays(90),
            'calculated_at' => now(),
            'metadata' => ['tmt_pangkat' => '2024-01-01'],
            'is_active' => true,
        ]);

        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_KGB,
            'milestone_date' => now()->addDays(60),
            'calculated_at' => now(),
            'metadata' => ['tmt_kgb' => '2024-06-01'],
            'is_active' => true,
        ]);

        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_PENSIUN,
            'milestone_date' => now()->addYear(),
            'calculated_at' => now(),
            'metadata' => ['source' => 'bup'],
            'is_active' => true,
        ]);

        // Track queries
        DB::enableQueryLog();
        $queryCountBefore = count(DB::getQueryLog());

        // Run scheduler
        app(EwsEngineService::class)->run();

        $queries = DB::getQueryLog();
        $queryCountAfter = count($queries);
        $totalQueries = $queryCountAfter - $queryCountBefore;

        // Verify alerts were created from milestones
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
        ]);

        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'KGB',
        ]);

        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'PENSIUN',
        ]);

        // Verify NO queries to appointments, rank_histories, salary_histories were made
        // (except for fallback scenarios)
        $expensiveQueries = collect($queries)->filter(function ($query) {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'rank_histories')
                || str_contains($sql, 'salary_histories')
                || (str_contains($sql, 'appointments') && ! str_contains($sql, 'supervisor_assignments'));
        });

        // Should have minimal to no expensive queries since milestones are precomputed
        $this->assertLessThan(5, $expensiveQueries->count(),
            'Scheduler should not query rank_histories/salary_histories/appointments when milestones exist');
    }

    public function test_scheduler_falls_back_to_calculation_when_milestones_missing(): void
    {
        // Setup: Create employee WITHOUT precomputed milestones but WITH source data
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'is_kinerja_baik' => true,
        ]);

        // Create rank history that will be due in 90 days (within threshold for H-90)
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => now()->subYears(4)->addDays(90)->toDateString(), // 4 years - 90 days ago
            'golongan' => 'III/a',
            'pangkat' => 'Penata Muda',
        ]);

        // Create KGB history that will be due in 60 days (within threshold for H-60)
        SalaryHistory::create([
            'employee_id' => $employee->id,
            'tmt_kgb' => now()->subYears(2)->addDays(60)->toDateString(), // 2 years - 60 days ago
            'gaji_pokok' => 3000000,
        ]);

        // Run scheduler (should fallback to calculation)
        app(EwsEngineService::class)->run();

        // Verify alerts were still created via fallback calculation
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
        ]);

        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'KGB',
        ]);
    }

    public function test_scheduler_respects_eligibility_flags_from_milestones(): void
    {
        // Create employee with poor performance (not eligible for promotion)
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'is_kinerja_baik' => false,  // Not eligible - no need for discipline record
        ]);

        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_KENAIKAN_PANGKAT,
            'milestone_date' => now()->addDays(90),
            'calculated_at' => now(),
            'metadata' => ['tmt_pangkat' => '2024-01-01'],
            'is_active' => true,
        ]);

        // Run scheduler
        app(EwsEngineService::class)->run();

        // Alert should be created but marked as not eligible
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'is_eligible' => false,
        ]);

        // Notification should NOT be created (sendNotification: false for non-eligible)
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.kenaikan_pangkat',
        ]);
    }

    public function test_scheduler_handles_satyalancana_milestones_with_years_metadata(): void
    {
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'is_satyalancana_eligible' => true,
        ]);

        // Precompute Satyalancana milestones with years metadata
        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_SATYALANCANA,
            'milestone_date' => now()->addDays(180),
            'calculated_at' => now(),
            'metadata' => ['satyalancana_years' => 10],
            'is_active' => true,
        ]);

        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_SATYALANCANA,
            'milestone_date' => now()->addYears(10)->addDays(180),
            'calculated_at' => now(),
            'metadata' => ['satyalancana_years' => 20],
            'is_active' => true,
        ]);

        // Run scheduler
        app(EwsEngineService::class)->run();

        // Verify 10-year alert was created (within threshold)
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'SATYALANCANA',
            'satyalancana_years' => 10,
        ]);

        // 20-year should not trigger yet (too far in future)
        $this->assertDatabaseMissing('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'SATYALANCANA',
            'satyalancana_years' => 20,
        ]);
    }

    public function test_scheduler_handles_pppk_contract_milestone(): void
    {
        $pppk = RefJenisPegawai::where('nama', 'PPPK')->first();

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'jenis_pegawai_id' => $pppk->id,
        ]);

        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_PPPK_CONTRACT_END,
            'milestone_date' => now()->addDays(180),
            'calculated_at' => now(),
            'metadata' => ['contract_start' => '2022-01-01'],
            'is_active' => true,
        ]);

        // Run scheduler
        app(EwsEngineService::class)->run();

        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'KONTRAK_PPPK',
        ]);
    }

    /** Fallback pensiun tanpa milestone harus memuat sumber BUP sekali per chunk, bukan per pegawai. */
    public function test_pension_fallback_queries_position_sources_once_per_chunk(): void
    {
        EwsConfig::updateOrCreate(['key' => 'pensiun_required_age_years'], ['value' => '0']);

        $jenisJabatan = RefJenisJabatan::create([
            'nama' => 'Jenis Jabatan Uji Query EWS',
            'maks_usia_pensiun' => 58,
            'is_active' => true,
        ]);
        $jabatan = RefJabatan::create([
            'nama' => 'Jabatan Uji Query EWS',
            'jenis_jabatan_id' => $jenisJabatan->id,
            'default_bup' => null,
            'is_active' => true,
        ]);

        $employees = Employee::factory()->count(8)->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => now()->subYears(58)->addDays(90)->toDateString(),
            'tanggal_pensiun' => null,
        ]);

        foreach ($employees as $employee) {
            $employee->positionHistories()->create([
                'jabatan_id' => $jabatan->id,
                'jenis_jabatan_id' => $jenisJabatan->id,
                'nama_jabatan' => $jabatan->nama,
                'tmt_jabatan' => '2020-01-01',
                'is_latest' => true,
            ]);
        }

        $this->assertDatabaseCount('employee_milestones', 0);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(EwsEngineService::class)->run();
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $positionQueries = $queries->filter(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'position_histories'),
        );
        $positionReferenceQueries = $queries->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'ref_jabatan') || str_contains($sql, 'ref_jenis_jabatan');
        });

        $this->assertCount(1, $positionQueries, 'Riwayat jabatan harus dimuat satu kali untuk satu chunk.');
        $this->assertLessThanOrEqual(2, $positionReferenceQueries->count(), 'Referensi BUP harus eager-loaded secara terbatas.');
    }
}
