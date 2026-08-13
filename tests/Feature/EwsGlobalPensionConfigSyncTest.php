<?php

namespace Tests\Feature;

use App\Actions\Ews\UpdateEwsConfigAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\EwsAlert;
use App\Models\EwsConfig;
use App\Models\PositionHistory;
use App\Models\RefJabatan;
use App\Models\User;
use App\Services\Employees\TmtCalculatorService;
use App\Services\Ews\EwsConfigCatalog;
use App\Services\EwsEngineService;
use Carbon\Carbon;
use Database\Seeders\EwsConfigSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class EwsGlobalPensionConfigSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(EwsConfigSeeder::class);
        Carbon::setTestNow('2026-08-11 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_global_pension_age_change_recalculates_snapshot_and_versions_milestone_used_by_scheduler(): void
    {
        EwsConfig::setVal('pensiun_required_age_years', '60');

        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1965-09-10',
            'tanggal_pensiun' => null,
            'status_aktif' => 'Aktif',
        ]);

        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $oldMilestone = $this->activePensionMilestone($employee);
        $this->assertSame('2025-09-10', $employee->refresh()->tanggal_pensiun?->toDateString());
        $this->assertSame('calculated_from_global_config', $oldMilestone->metadata['source']);
        $this->assertSame(60, $oldMilestone->metadata['bup']);

        app(UpdateEwsConfigAction::class)->execute($this->configRequest(61));

        $newMilestone = $this->activePensionMilestone($employee);

        $this->assertSame('61', EwsConfig::getVal('pensiun_required_age_years'));
        $this->assertSame('2026-09-10', $employee->refresh()->tanggal_pensiun?->toDateString());
        $this->assertNotSame($oldMilestone->id, $newMilestone->id);
        $this->assertSame('2026-09-10', $newMilestone->milestone_date->toDateString());
        $this->assertSame('calculated_from_global_config', $newMilestone->metadata['source']);
        $this->assertSame('pensiun_required_age_years', $newMilestone->metadata['config_key']);
        $this->assertSame(61, $newMilestone->metadata['bup']);
        $this->assertFalse($oldMilestone->refresh()->is_active);
        $this->assertSame('2025-09-10', $oldMilestone->milestone_date->toDateString());

        app(EwsEngineService::class)->run();

        $alert = EwsAlert::query()
            ->where('employee_id', $employee->id)
            ->where('type', 'PENSIUN')
            ->sole();

        $this->assertSame('2026-09-10', $alert->target_date->toDateString());
        $this->assertSame(90, $alert->interval_days);
    }

    public function test_global_pension_age_change_does_not_mutate_non_pension_snapshots_or_milestones(): void
    {
        EwsConfig::setVal('pensiun_required_age_years', '60');

        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1965-09-10',
            'tanggal_pensiun' => null,
        ]);
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $employee->update([
            'tanggal_kenaikan_pangkat_berikutnya' => '2040-01-02',
            'tanggal_kgb_berikutnya' => '2041-03-04',
        ]);
        $sentinelMilestone = EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_KENAIKAN_PANGKAT,
            'milestone_key' => EmployeeMilestone::KEY_DEFAULT,
            'milestone_date' => '2040-01-02',
            'calculated_at' => '2026-08-01',
            'metadata' => [
                'source' => 'sentinel_stale_non_pension',
                'required_years' => 99,
            ],
            'is_active' => true,
        ]);

        app(UpdateEwsConfigAction::class)->execute($this->configRequest(61));

        $employee->refresh();
        $sentinelMilestone->refresh();

        $this->assertSame('2040-01-02', $employee->tanggal_kenaikan_pangkat_berikutnya?->toDateString());
        $this->assertSame('2041-03-04', $employee->tanggal_kgb_berikutnya?->toDateString());
        $this->assertTrue($sentinelMilestone->is_active);
        $this->assertSame('2040-01-02', $sentinelMilestone->milestone_date->toDateString());
        $this->assertSame('sentinel_stale_non_pension', $sentinelMilestone->metadata['source']);
        $this->assertSame(99, $sentinelMilestone->metadata['required_years']);
    }

    public function test_global_pension_age_change_preserves_higher_priority_and_authoritative_provenance(): void
    {
        EwsConfig::setVal('pensiun_required_age_years', '60');

        $jabatan = RefJabatan::create([
            'nama' => 'Jabatan BUP Kanonis',
            'default_bup' => 58,
            'is_active' => true,
        ]);
        $bupEmployee = Employee::factory()->create([
            'tanggal_lahir' => '1970-01-15',
            'tanggal_pensiun' => null,
        ]);
        PositionHistory::create([
            'employee_id' => $bupEmployee->id,
            'jabatan_id' => $jabatan->id,
            'nama_jabatan' => $jabatan->nama,
            'tmt_jabatan' => '2020-01-01',
            'is_latest' => true,
        ]);
        app(TmtCalculatorService::class)->syncForEmployee($bupEmployee);

        $officialEmployee = Employee::factory()->create([
            'tanggal_lahir' => '1971-02-16',
            'tanggal_pensiun' => '2034-06-30',
        ]);
        app(TmtCalculatorService::class)->syncForEmployee($officialEmployee, true);

        $importEmployee = Employee::factory()->create([
            'tanggal_lahir' => '1972-03-17',
            'tanggal_pensiun' => '2035-07-31',
        ]);
        app(TmtCalculatorService::class)->recordImportedPensionDate($importEmployee);

        $legacyEmployee = Employee::factory()->create([
            'tanggal_lahir' => '1973-04-18',
            'tanggal_pensiun' => '2036-08-31',
        ]);
        app(TmtCalculatorService::class)->syncForEmployee($legacyEmployee);

        $protectedMilestones = collect([
            'calculated_from_bup' => $bupEmployee,
            'employees.tanggal_pensiun' => $officialEmployee,
            'employee_import' => $importEmployee,
            'legacy_unverified' => $legacyEmployee,
        ])->mapWithKeys(function (Employee $employee, string $source): array {
            $milestone = $this->activePensionMilestone($employee);
            $this->assertSame($source, $milestone->metadata['source']);

            return [$employee->id => [
                'snapshot' => $employee->refresh()->tanggal_pensiun?->toDateString(),
                'milestone_id' => $milestone->id,
                'milestone_date' => $milestone->milestone_date->toDateString(),
                'source' => $source,
            ]];
        });

        app(UpdateEwsConfigAction::class)->execute($this->configRequest(61));

        foreach ($protectedMilestones as $employeeId => $expected) {
            $employee = Employee::findOrFail($employeeId);
            $milestone = $this->activePensionMilestone($employee);

            $this->assertSame($expected['snapshot'], $employee->tanggal_pensiun?->toDateString());
            $this->assertSame($expected['milestone_id'], $milestone->id);
            $this->assertSame($expected['milestone_date'], $milestone->milestone_date->toDateString());
            $this->assertSame($expected['source'], $milestone->metadata['source']);
        }
    }

    public function test_unchanged_global_pension_age_does_not_touch_snapshot_milestone_or_audit(): void
    {
        EwsConfig::setVal('pensiun_required_age_years', '60');

        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1965-09-10',
            'tanggal_pensiun' => null,
        ]);
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $milestone = $this->activePensionMilestone($employee);
        $auditCount = AuditLog::count();
        Carbon::setTestNow('2026-08-11 09:00:00');

        app(UpdateEwsConfigAction::class)->execute($this->configRequest(60));

        $this->assertSame($auditCount, AuditLog::count());
        $this->assertSame('2025-09-10', $employee->refresh()->tanggal_pensiun?->toDateString());
        $this->assertSame($milestone->id, $this->activePensionMilestone($employee)->id);
        $this->assertSame(
            $milestone->updated_at->toDateTimeString(),
            $milestone->refresh()->updated_at->toDateTimeString(),
        );
    }

    public function test_global_pension_sync_failure_rolls_back_config_audit_snapshot_and_milestone(): void
    {
        EwsConfig::setVal('pensiun_required_age_years', '60');

        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1965-09-10',
            'tanggal_pensiun' => null,
        ]);
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $oldMilestone = $this->activePensionMilestone($employee);
        $auditCount = AuditLog::count();

        $eventName = 'eloquent.created: '.EmployeeMilestone::class;
        $eventDispatcher = app(Dispatcher::class);
        $existingListeners = $eventDispatcher->getRawListeners()[$eventName] ?? [];
        $afterWriteStateObserved = false;
        $listener = function (EmployeeMilestone $milestone) use ($employee, &$afterWriteStateObserved): void {
            if (
                $milestone->type !== EmployeeMilestone::TYPE_PENSIUN
                || ($milestone->metadata['source'] ?? null) !== 'calculated_from_global_config'
                || ($milestone->metadata['bup'] ?? null) !== 61
            ) {
                return;
            }

            $afterWriteStateObserved = Employee::findOrFail($employee->id)
                ->tanggal_pensiun?->toDateString() === '2026-09-10'
                && EmployeeMilestone::query()
                    ->whereKey($milestone->id)
                    ->where('is_active', true)
                    ->exists();

            throw new RuntimeException('Simulasi kegagalan setelah snapshot dan milestone baru ditulis.');
        };
        Event::listen($eventName, $listener);

        $caughtException = null;

        try {
            app(UpdateEwsConfigAction::class)->execute($this->configRequest(61));
        } catch (RuntimeException $exception) {
            $caughtException = $exception;
        } finally {
            Event::forget($eventName);

            foreach ($existingListeners as $existingListener) {
                Event::listen($eventName, $existingListener);
            }
        }

        $this->assertNotNull($caughtException);
        $this->assertSame(
            'Simulasi kegagalan setelah snapshot dan milestone baru ditulis.',
            $caughtException->getMessage(),
        );
        $this->assertTrue($afterWriteStateObserved);
        $this->assertSame('60', EwsConfig::getVal('pensiun_required_age_years'));
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertSame('2025-09-10', $employee->refresh()->tanggal_pensiun?->toDateString());
        $this->assertTrue($oldMilestone->refresh()->is_active);
        $this->assertSame($oldMilestone->id, $this->activePensionMilestone($employee)->id);
        $this->assertSame(1, EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->count());
    }

    private function activePensionMilestone(Employee $employee): EmployeeMilestone
    {
        return EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->where('milestone_key', EmployeeMilestone::KEY_DEFAULT)
            ->where('is_active', true)
            ->sole();
    }

    private function configRequest(int $globalPensionAge): Request
    {
        $payload = array_merge(EwsConfigCatalog::DEFAULTS, [
            'pensiun_required_age_years' => (string) $globalPensionAge,
            'reason' => 'Penyesuaian fallback BUP global.',
        ]);
        $request = Request::create('/konfigurasi/update', 'POST', $payload);
        $request->setUserResolver(fn (): User => User::factory()->create(['role' => 'super_admin']));

        return $request;
    }
}
