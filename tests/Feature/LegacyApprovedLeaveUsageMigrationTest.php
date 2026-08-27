<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\User;
use App\Queries\Cuti\CutiRekapQuery;
use App\Services\Cuti\LeaveBalanceService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GuardsDestructiveMigrationTestEnvironment;
use Tests\TestCase;

/**
 * Jalur ini sengaja destruktif dan serial agar urutan migration upgrade nyata dapat diuji
 * pada database test standar setelah full suite tidak lagi membutuhkannya.
 */
#[Group('guarded-destructive')]
class LegacyApprovedLeaveUsageMigrationTest extends TestCase
{
    use GuardsDestructiveMigrationTestEnvironment;

    private const DESTRUCTIVE_MIGRATION_OPT_IN = 'SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS';

    private const STANDARD_TEST_DATABASE = 'simpeg_test';

    private const MIGRATION = 'database/migrations/2026_08_23_000003_backfill_legacy_approved_leave_usage.php';

    public function test_upgrade_memakai_tahun_wita_saat_utc_masih_tahun_sebelumnya(): void
    {
        $this->requireStandardDestructiveTestDatabase();
        Carbon::setTestNow(Carbon::parse('2026-12-31 16:30:00', 'UTC'));

        try {
            $this->assertSame(2026, Carbon::now('UTC')->year);
            $this->assertSame(2027, Carbon::now('Asia/Makassar')->year);
            $this->assertSame(0, Artisan::call('migrate:fresh', [
                '--path' => $this->migrationPathsBeforeFactTable(),
                '--force' => true,
            ]), Artisan::output());

            $annual = $this->leaveType('tahunan', 'Cuti Tahunan', true);
            $sick = $this->leaveType('sakit-boundary', 'Cuti Sakit Boundary', false);
            $employee = $this->eligiblePnsEmployee();
            $witaCurrentAnnual = $this->approvedRequest($employee, $annual, '2027-01-04', '2027-01-04', 1);
            $witaFutureAnnual = $this->approvedRequest($employee, $annual, '2028-01-03', '2028-01-03', 1);

            try {
                Artisan::call('migrate', [
                    '--path' => $this->migrationPathsFromFactTable(),
                    '--force' => true,
                ]);
                $this->fail('Migration wajib menolak tahun setelah 2027 WITA.');
            } catch (\RuntimeException $exception) {
                $failedOutput = $exception->getMessage()."\n".Artisan::output();
            }

            $this->assertStringContainsString($witaFutureAnnual->id, $failedOutput);
            $this->assertStringNotContainsString($witaCurrentAnnual->id, $failedOutput);
            $this->assertDatabaseCount('leave_usage_records', 0);
            $this->assertDatabaseCount('leave_balance_ledger', 0);
            $this->assertDatabaseCount('leave_balances', 0);
            $this->assertDatabaseCount('audit_logs', 0);

            DB::table('leave_requests')->where('id', $witaFutureAnnual->id)->update([
                'jenis_cuti_id' => $sick->id,
            ]);
            $this->assertSame(0, Artisan::call('migrate', [
                '--path' => $this->migrationPathsFromFactTable(),
                '--force' => true,
            ]), Artisan::output());

            $this->assertDatabaseHas('leave_usage_records', [
                'leave_request_id' => $witaCurrentAnnual->id,
                'usage_year' => 2027,
            ]);
            $this->assertDatabaseHas('leave_usage_records', [
                'leave_request_id' => $witaFutureAnnual->id,
                'leave_type_id' => $sick->id,
                'usage_year' => 2028,
            ]);
            $this->assertDatabaseHas('leave_balances', [
                'employee_id' => $employee->id,
                'tahun' => 2027,
            ]);
            $this->assertSame(0, LeaveBalance::query()->where('tahun', 2028)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_upgrade_rejects_future_annual_atomically_but_accepts_future_nonannual(): void
    {
        $this->requireStandardDestructiveTestDatabase();
        Carbon::setTestNow('2026-08-23 09:00:00');

        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', [
                '--path' => $this->migrationPathsBeforeFactTable(),
                '--force' => true,
            ]), Artisan::output());

            $annual = $this->leaveType('tahunan', 'Cuti Tahunan', true);
            $sick = $this->leaveType('sakit', 'Cuti Sakit', false);
            $employee = $this->eligiblePnsEmployee();
            $currentAnnual = $this->approvedRequest($employee, $annual, '2026-09-01', '2026-09-01', 1);
            $futureAnnual = $this->approvedRequest($employee, $annual, '2027-01-04', '2027-01-04', 1);
            $futureNonAnnual = $this->approvedRequest($employee, $sick, '2027-02-01', '2027-02-02', 2);

            try {
                Artisan::call('migrate', [
                    '--path' => $this->migrationPathsFromFactTable(),
                    '--force' => true,
                ]);
                $this->fail('Migration wajib menolak kandidat Cuti Tahunan setelah tahun WITA berjalan.');
            } catch (\RuntimeException $exception) {
                $failedOutput = $exception->getMessage()."\n".Artisan::output();
            }

            $this->assertStringContainsString($futureAnnual->id, $failedOutput);
            $this->assertStringContainsString('setelah tahun WITA berjalan', $failedOutput);
            $this->assertDatabaseCount('leave_usage_records', 0);
            $this->assertDatabaseCount('leave_balance_ledger', 0);
            $this->assertDatabaseCount('leave_balances', 0);
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertFalse($this->cutoverMigrationWasRecorded());

            DB::table('leave_requests')->where('id', $futureAnnual->id)->update([
                'jenis_cuti_id' => $sick->id,
            ]);

            $this->assertSame(0, Artisan::call('migrate', [
                '--path' => $this->migrationPathsFromFactTable(),
                '--force' => true,
            ]), Artisan::output());

            $this->assertDatabaseHas('leave_usage_records', [
                'leave_request_id' => $currentAnnual->id,
                'usage_year' => 2026,
            ]);
            $this->assertDatabaseHas('leave_usage_records', [
                'leave_request_id' => $futureAnnual->id,
                'leave_type_id' => $sick->id,
                'usage_year' => 2027,
            ]);
            $this->assertDatabaseHas('leave_usage_records', [
                'leave_request_id' => $futureNonAnnual->id,
                'leave_type_id' => $sick->id,
                'usage_year' => 2027,
            ]);
            $this->assertSame(0, LeaveBalance::query()->where('tahun', 2027)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_upgrade_backfills_legacy_approved_requests_atomically_and_idempotently(): void
    {
        $this->requireStandardDestructiveTestDatabase();
        $this->assertFileExists(base_path(self::MIGRATION));

        Carbon::setTestNow('2026-08-23 09:00:00');

        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', [
                '--path' => $this->migrationPathsBeforeFactTable(),
                '--force' => true,
            ]), Artisan::output());
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('leave_usage_records'));
            $this->allowInvalidLegacyFixtureColumns();

            $annual = $this->leaveType('tahunan', 'Cuti Tahunan', true);
            $sick = $this->leaveType('sakit', 'Cuti Sakit', false);
            $large = $this->leaveType('besar', 'Cuti Besar', false);
            $annualEmployee = $this->eligiblePnsEmployee();
            $nonAnnualEmployee = $this->eligiblePnsEmployee();
            $largeEmployee = $this->eligiblePnsEmployee();
            $approver = Employee::factory()->create();
            $approverUser = User::factory()->pimpinan()->create(['employee_id' => $approver->id]);

            $annual2025 = $this->approvedRequest(
                $annualEmployee,
                $annual,
                '2025-06-02',
                '2025-06-03',
                2,
                $approver,
            );
            $annual2026 = $this->approvedRequest(
                $annualEmployee,
                $annual,
                '2026-03-02',
                '2026-03-04',
                3,
                $approver,
            );
            $sickRequest = $this->approvedRequest(
                $nonAnnualEmployee,
                $sick,
                '2026-04-06',
                '2026-04-07',
                2,
            );
            DB::table('leave_approvals')->insert([
                'id' => (string) Str::uuid(),
                'leave_request_id' => $sickRequest->id,
                'approver_id' => $approver->id,
                'stage' => 99,
                'action' => 'APPROVE',
                'komentar' => 'Approval legacy non-final tidak membuktikan aktor keputusan final.',
                'acted_at' => Carbon::parse('2026-04-08 09:00:00'),
                'created_at' => Carbon::parse('2026-04-08 09:00:00'),
                'updated_at' => Carbon::parse('2026-04-08 09:00:00'),
            ]);
            $largeRequest = $this->approvedRequest(
                $largeEmployee,
                $large,
                '2026-05-04',
                '2026-05-08',
                5,
            );

            $invalid = [
                'cross_year' => $this->approvedRequest(
                    $annualEmployee,
                    $annual,
                    '2025-12-31',
                    '2026-01-02',
                    2,
                    $approver,
                ),
                'zero_workdays' => $this->approvedRequest(
                    $annualEmployee,
                    $annual,
                    '2026-06-01',
                    '2026-06-01',
                    0,
                    $approver,
                ),
                'missing_type' => $this->rawApprovedRequest($annualEmployee, null, '2026-07-01', '2026-07-01', 1),
                'missing_date' => $this->rawApprovedRequest($annualEmployee, $annual->id, null, '2026-08-01', 1),
            ];

            try {
                Artisan::call('migrate', [
                    '--path' => $this->migrationPathsFromFactTable(),
                    '--force' => true,
                ]);
                $this->fail('Migration seharusnya gagal sebelum menulis saat legacy invalid ditemukan.');
            } catch (\RuntimeException $exception) {
                $failedOutput = $exception->getMessage()."\n".Artisan::output();
            }

            foreach ($invalid as $request) {
                $this->assertStringContainsString($request->id, $failedOutput);
            }
            $this->assertStringContainsString('melintasi tahun', $failedOutput);
            $this->assertStringContainsString('lebih dari nol', $failedOutput);
            $this->assertStringContainsString('jenis cuti', mb_strtolower($failedOutput));
            $this->assertStringContainsString('tanggal mulai', mb_strtolower($failedOutput));
            $this->assertDatabaseCount('leave_usage_records', 0);
            $this->assertSame(0, LeaveBalanceLedger::query()
                ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED)
                ->count());
            $this->assertDatabaseCount('leave_balances', 0);
            $this->assertSame(0, AuditLog::query()
                ->where('auditable_type', 'LeaveUsageRecord')
                ->count());
            $this->assertFalse($this->cutoverMigrationWasRecorded());

            DB::table('leave_requests')->where('id', $invalid['cross_year']->id)->update([
                'tanggal_selesai' => '2025-12-31',
                'jumlah_hari_kerja' => 1,
            ]);
            DB::table('leave_requests')->where('id', $invalid['zero_workdays']->id)->update([
                'jumlah_hari_kerja' => 1,
            ]);
            DB::table('leave_requests')->where('id', $invalid['missing_type']->id)->update([
                'jenis_cuti_id' => $sick->id,
            ]);
            DB::table('leave_requests')->where('id', $invalid['missing_date']->id)->update([
                'tanggal_mulai' => '2026-08-01',
            ]);

            $cutoverQueries = [];
            DB::listen(static function (QueryExecuted $query) use (&$cutoverQueries): void {
                $cutoverQueries[] = mb_strtolower($query->sql);
            });

            $this->assertSame(0, Artisan::call('migrate', [
                '--path' => $this->migrationPathsFromFactTable(),
                '--force' => true,
            ]), Artisan::output());

            $this->assertDatabaseCount('leave_usage_records', 8);
            $this->assertSame(8, LeaveBalanceLedger::query()
                ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED)
                ->count());
            $this->assertSame(8, AuditLog::query()
                ->where('auditable_type', 'LeaveUsageRecord')
                ->where('new_values->provenance', 'database_upgrade')
                ->count());

            $annualFact = LeaveUsageRecord::query()
                ->where('leave_request_id', $annual2026->id)
                ->sole();
            $this->assertSame($approverUser->id, $annualFact->recorded_by);
            $this->assertSame($approverUser->id, LeaveBalanceLedger::query()
                ->where('leave_request_id', $annual2026->id)
                ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED)
                ->sole()
                ->created_by);
            $this->assertSame('final_step_user_mapping', AuditLog::query()
                ->where('auditable_type', 'LeaveUsageRecord')
                ->where('auditable_id', $annualFact->id)
                ->sole()
                ->new_values['historical_actor_evidence']);

            $sickFact = LeaveUsageRecord::query()
                ->where('leave_request_id', $sickRequest->id)
                ->sole();
            $this->assertNull($sickFact->recorded_by);
            $this->assertNull(LeaveBalanceLedger::query()
                ->where('leave_request_id', $sickRequest->id)
                ->where('event_type', LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED)
                ->sole()
                ->created_by);
            $this->assertSame('historical_actor_unresolved', AuditLog::query()
                ->where('auditable_type', 'LeaveUsageRecord')
                ->where('auditable_id', $sickFact->id)
                ->sole()
                ->new_values['historical_actor_evidence']);
            $this->assertTrue(collect($cutoverQueries)->contains(
                fn (string $sql): bool => str_contains($sql, 'create temporary table simpeg_backfill_annual_replay'),
            ), 'Grouping replay wajib ditampung PostgreSQL, bukan array linear di PHP.');
            $this->assertTrue(collect($cutoverQueries)->contains(
                fn (string $sql): bool => str_contains($sql, 'on conflict (employee_id)')
                    && str_contains($sql, 'least('),
            ), 'Tahun paling awal wajib diagregasi dengan upsert MIN yang idempoten.');
            $this->assertTrue(collect($cutoverQueries)->contains(
                fn (string $sql): bool => str_contains($sql, 'from "simpeg_backfill_annual_replay"')
                    && str_contains($sql, 'limit 100'),
            ), 'Replay employee dari temporary table wajib dibaca keyset secara bounded.');

            $this->assertSame(2, LeaveBalance::query()
                ->where('employee_id', $annualEmployee->id)
                ->count());
            $this->assertDatabaseHas('leave_balances', [
                'employee_id' => $annualEmployee->id,
                'tahun' => 2025,
                'terpakai' => 3,
                'sisa' => 9,
            ]);
            $this->assertDatabaseHas('leave_balances', [
                'employee_id' => $annualEmployee->id,
                'tahun' => 2026,
                'terpakai' => 5,
                'sisa' => 13,
            ]);
            $this->assertSame(2, LeaveBalanceLedger::query()
                ->where('employee_id', $annualEmployee->id)
                ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
                ->count(), 'Dua tahun projection harus berasal dari satu replay grouped, bukan replay per request.');
            $this->assertSame(0, LeaveBalance::query()
                ->whereIn('employee_id', [$nonAnnualEmployee->id, $largeEmployee->id])
                ->count(), 'Fakta non-tahunan tidak boleh memicu replay saldo.');

            $summary = app(CutiRekapQuery::class)->summaryRows([
                'periode' => '2026',
                'pegawai' => $annualEmployee->id,
            ]);
            $this->assertSame(5, $summary->where('jenis', 'Cuti Tahunan')->sum('total_hari'));

            try {
                app(LeaveBalanceService::class)->assertAnnualLeaveAllowed($largeEmployee, 2026);
                $this->fail('Rule 5 seharusnya mengenali fakta Cuti Besar hasil backfill.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('tanggal_mulai', $exception->errors());
            }

            $beforeRetry = $this->effectSnapshot();
            $this->forgetCutoverMigration();
            $this->assertSame(0, Artisan::call('migrate', [
                '--path' => self::MIGRATION,
                '--force' => true,
            ]), Artisan::output());
            $this->assertSame($beforeRetry, $this->effectSnapshot());
            $this->assertSame($annual2025->id, LeaveUsageRecord::query()
                ->where('leave_request_id', $annual2025->id)
                ->sole()
                ->leave_request_id);
            $this->assertSame($largeRequest->id, LeaveUsageRecord::query()
                ->where('leave_request_id', $largeRequest->id)
                ->sole()
                ->leave_request_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_upgrade_memigrasikan_pengajuan_tahunan_pegawai_soft_deleted_tanpa_memulihkan_pegawai(): void
    {
        $this->requireStandardDestructiveTestDatabase();
        Carbon::setTestNow('2026-08-23 09:00:00');

        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', [
                '--path' => $this->migrationPathsBeforeFactTable(),
                '--force' => true,
            ]), Artisan::output());

            $annual = $this->leaveType('tahunan', 'Cuti Tahunan', true);
            $employee = $this->eligiblePnsEmployee();
            $request = $this->approvedRequest($employee, $annual, '2026-06-02', '2026-06-03', 2);
            $deletedAtBefore = now()->subDay()->startOfSecond();
            DB::table('employees')->where('id', $employee->id)->update([
                'deleted_at' => $deletedAtBefore,
            ]);

            $this->assertNotNull($deletedAtBefore);
            $this->assertSame(
                $deletedAtBefore->toISOString(),
                Carbon::parse(DB::table('employees')->where('id', $employee->id)->value('deleted_at'))->toISOString(),
            );

            $this->assertSame(0, Artisan::call('migrate', [
                '--path' => $this->migrationPathsFromFactTable(),
                '--force' => true,
            ]), Artisan::output());

            $fact = LeaveUsageRecord::query()
                ->where('leave_request_id', $request->id)
                ->sole();
            $this->assertSame($employee->id, $fact->employee_id);
            $this->assertSame(2, $fact->workdays);
            $this->assertDatabaseHas('leave_balance_ledger', [
                'employee_id' => $employee->id,
                'leave_request_id' => $request->id,
                'event_type' => LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED,
                'amount' => 2,
            ]);
            $this->assertSame(1, LeaveBalanceLedger::query()
                ->where('employee_id', $employee->id)
                ->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)
                ->count());
            $this->assertDatabaseHas('leave_balances', [
                'employee_id' => $employee->id,
                'tahun' => 2026,
                'jatah_awal' => 12,
                'terpakai' => 2,
                'sisa' => 10,
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'auditable_type' => 'LeaveUsageRecord',
                'auditable_id' => $fact->id,
                'new_values->provenance' => 'database_upgrade',
            ]);
            $this->assertSame(1, AuditLog::query()
                ->where('auditable_type', 'LeaveBalance')
                ->where('new_values->employee_id', $employee->id)
                ->where('new_values->operation', 'balance_recalculated')
                ->count());

            $this->assertLegacyEmployeeLifecyclePreserved($employee->id, $deletedAtBefore->toISOString());

            $beforeRetry = $this->effectSnapshot();
            $this->forgetCutoverMigration();
            $this->assertSame(0, Artisan::call('migrate', [
                '--path' => self::MIGRATION,
                '--force' => true,
            ]), Artisan::output());
            $this->assertSame($beforeRetry, $this->effectSnapshot());
            $this->assertLegacyEmployeeLifecyclePreserved($employee->id, $deletedAtBefore->toISOString());
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Upgrade mandiri mempertahankan marker legacy, sedangkan lifecycle canonical
     * mengubah row yang sama menjadi status Nonaktif tanpa menghapus pegawai.
     */
    private function assertLegacyEmployeeLifecyclePreserved(string $employeeId, string $deletedAtBefore): void
    {
        if (Schema::hasColumn('employees', 'deleted_at')) {
            $deletedAtAfter = DB::table('employees')->where('id', $employeeId)->value('deleted_at');

            $this->assertSame($deletedAtBefore, Carbon::parse($deletedAtAfter)->toISOString());

            return;
        }

        $employee = DB::table('employees')
            ->join('ref_status_pegawai', 'ref_status_pegawai.id', '=', 'employees.status_pegawai_id')
            ->where('employees.id', $employeeId)
            ->sole(['employees.id', 'ref_status_pegawai.kelompok']);

        $this->assertSame($employeeId, $employee->id);
        $this->assertSame('Nonaktif', $employee->kelompok);
    }

    public function test_collision_dedup_ledger_tanpa_fact_menggagalkan_seluruh_backfill_tanpa_state_parsial(): void
    {
        $this->requireStandardDestructiveTestDatabase();
        Carbon::setTestNow('2026-08-23 09:00:00');

        try {
            $cutoverName = pathinfo(self::MIGRATION, PATHINFO_FILENAME);
            $this->assertSame(0, Artisan::call('migrate:fresh', [
                '--path' => $this->migrationPaths(fn (string $name): bool => $name < $cutoverName),
                '--force' => true,
            ]), Artisan::output());
            $sick = $this->leaveType('sakit-ledger-collision', 'Cuti Sakit Collision', false);
            $employee = $this->eligiblePnsEmployee();
            $collisionEmployee = $this->eligiblePnsEmployee();
            $request = $this->approvedRequest($employee, $sick, '2026-04-06', '2026-04-07', 2);
            $collisionMetadata = [
                'usage_record_id' => (string) Str::uuid(),
                'source_type' => 'collision_fixture',
                'record_status' => 'active',
                'operation' => 'unexpected_collision',
                'provenance' => 'test_fixture',
            ];
            $collision = LeaveBalanceLedger::query()->create([
                'employee_id' => $collisionEmployee->id,
                'leave_request_id' => $request->id,
                'tahun' => 2025,
                'event_type' => LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED,
                'amount' => 99,
                'source_year' => 2025,
                'reason' => 'Fixture collision dedup yang tidak sah.',
                'dedup_key' => "usage:backfill:approved_request:{$request->id}",
                'metadata' => $collisionMetadata,
                'created_by' => null,
                'occurred_at' => now(),
            ]);

            $failure = null;
            try {
                Artisan::call('migrate', [
                    '--path' => self::MIGRATION,
                    '--force' => true,
                ]);
            } catch (\Throwable $exception) {
                $failure = $exception;
            }

            $this->assertNotNull($failure, 'Collision dedup tanpa fact wajib dipropagasikan, bukan dipakai diam-diam.');
            $this->assertSame('23505', (string) $failure->getCode());
            $this->assertDatabaseCount('leave_usage_records', 0);
            $this->assertDatabaseCount('leave_balance_ledger', 1);
            $this->assertDatabaseHas('leave_balance_ledger', [
                'id' => $collision->id,
                'employee_id' => $collisionEmployee->id,
                'tahun' => 2025,
                'amount' => 99,
            ]);
            $this->assertSame($collisionMetadata, $collision->fresh()->metadata);
            $this->assertDatabaseCount('leave_balances', 0);
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertFalse($this->cutoverMigrationWasRecorded());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rollback_cutover_fail_closed_sebelum_schema_histori_diturunkan(): void
    {
        $this->requireStandardDestructiveTestDatabase();
        Carbon::setTestNow('2026-08-23 09:00:00');

        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', [
                '--path' => $this->migrationPathsBeforeFactTable(),
                '--force' => true,
            ]), Artisan::output());
            $sick = $this->leaveType('sakit', 'Cuti Sakit', false);
            $employee = $this->eligiblePnsEmployee();
            $request = $this->approvedRequest($employee, $sick, '2026-04-06', '2026-04-07', 2);

            $this->assertSame(0, Artisan::call('migrate', [
                '--path' => $this->migrationPathsFromFactTable(),
                '--force' => true,
            ]), Artisan::output());
            $cutoverMigrationName = pathinfo(self::MIGRATION, PATHINFO_FILENAME);
            $migrationHistoryThroughCutover = DB::table('migrations')
                ->where('migration', '<=', $cutoverMigrationName)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => [
                    'migration' => $row->migration,
                    'batch' => $row->batch,
                ])->all();
            $batch = (int) DB::table('migrations')->max('batch');

            try {
                Artisan::call('migrate:rollback', [
                    '--batch' => $batch,
                    '--force' => true,
                ]);
                $this->fail('Rollback batch wajib dihentikan oleh migration cutover.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('tidak dapat di-rollback', mb_strtolower($exception->getMessage()));
            }

            $this->assertTrue(DB::getSchemaBuilder()->hasTable('leave_usage_records'));
            $this->assertDatabaseHas('leave_usage_records', ['leave_request_id' => $request->id]);
            $this->assertTrue($this->cutoverMigrationWasRecorded());
            $this->assertSame(
                $migrationHistoryThroughCutover,
                DB::table('migrations')
                    ->where('migration', '<=', $cutoverMigrationName)
                    ->orderBy('id')
                    ->get()
                    ->map(fn (object $row): array => [
                        'migration' => $row->migration,
                        'batch' => $row->batch,
                    ])->all(),
                'Rollback gagal tidak boleh menghapus atau mengubah histori hingga migration cutover.',
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Menahan migrate:fresh kecuali operator memilih jalur destruktif pada database test standar.
     */
    private function requireStandardDestructiveTestDatabase(): void
    {
        $optIn = $_SERVER[self::DESTRUCTIVE_MIGRATION_OPT_IN]
            ?? $_ENV[self::DESTRUCTIVE_MIGRATION_OPT_IN]
            ?? getenv(self::DESTRUCTIVE_MIGRATION_OPT_IN);

        if ($optIn !== 'true') {
            $this->markTestSkipped('Set SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS=true untuk menjalankan cutover destruktif.');
        }

        $environment = app()->environment();
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        if ($environment !== 'testing' || $driver !== 'pgsql' || $database !== self::STANDARD_TEST_DATABASE) {
            $this->fail(sprintf(
                'Cutover destruktif ditolak: wajib APP_ENV=testing, driver pgsql, dan database %s; aktual environment=%s, driver=%s, database=%s.',
                self::STANDARD_TEST_DATABASE,
                $environment,
                $driver,
                $database,
            ));
        }
    }

    private function forgetCutoverMigration(): void
    {
        DB::table('migrations')->where('migration', pathinfo(self::MIGRATION, PATHINFO_FILENAME))->delete();
    }

    /** @return list<string> */
    private function migrationPathsBeforeFactTable(): array
    {
        return $this->migrationPaths(fn (string $name): bool => $name < '2026_08_18_000002_create_leave_usage_records_table');
    }

    /** @return list<string> */
    private function migrationPathsFromFactTable(): array
    {
        return $this->migrationPaths(fn (string $name): bool => $name >= '2026_08_18_000002_create_leave_usage_records_table');
    }

    /**
     * @param  callable(string): bool  $include
     * @return list<string>
     */
    private function migrationPaths(callable $include): array
    {
        $paths = glob(database_path('migrations/*.php')) ?: [];

        return collect($paths)
            ->sort()
            ->filter(fn (string $path): bool => $include(pathinfo($path, PATHINFO_FILENAME)))
            ->map(fn (string $path): string => 'database/migrations/'.basename($path))
            ->values()
            ->all();
    }

    private function cutoverMigrationWasRecorded(): bool
    {
        return DB::table('migrations')
            ->where('migration', pathinfo(self::MIGRATION, PATHINFO_FILENAME))
            ->exists();
    }

    /** Fixture legacy invalid membutuhkan pelonggaran yang hanya hidup di database disposable ini. */
    private function allowInvalidLegacyFixtureColumns(): void
    {
        DB::statement('ALTER TABLE leave_requests ALTER COLUMN jenis_cuti_id DROP NOT NULL');
        DB::statement('ALTER TABLE leave_requests ALTER COLUMN tanggal_mulai DROP NOT NULL');
    }

    private function leaveType(string $code, string $name, bool $annual): RefJenisCuti
    {
        return RefJenisCuti::query()->create([
            'code' => $code,
            'nama' => $name,
            'mengurangi_saldo_tahunan' => $annual,
            'khusus_pns' => false,
        ]);
    }

    private function eligiblePnsEmployee(): Employee
    {
        $pns = RefJenisPegawai::query()->firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-LEGACY-'.Str::upper(Str::random(8)),
            'tanggal_sk' => '2020-01-01',
        ]);

        return $employee;
    }

    private function approvedRequest(
        Employee $employee,
        RefJenisCuti $leaveType,
        string $startDate,
        string $endDate,
        int $workdays,
        ?Employee $approver = null,
    ): LeaveRequest {
        $request = $this->rawApprovedRequest($employee, $leaveType->id, $startDate, $endDate, $workdays);

        if ($approver !== null) {
            LeaveRequestStep::query()->create([
                'leave_request_id' => $request->id,
                'step_order' => 1,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => $approver->id,
                'status' => 'approved',
                'is_final' => true,
                'acted_at' => Carbon::parse($endDate)->endOfDay(),
                'decision_note' => 'Persetujuan final legacy yang dapat dibuktikan.',
            ]);
        }

        return $request;
    }

    private function rawApprovedRequest(
        Employee $employee,
        ?string $leaveTypeId,
        ?string $startDate,
        ?string $endDate,
        int $workdays,
    ): LeaveRequest {
        $id = (string) Str::uuid();
        DB::table('leave_requests')->insert([
            'id' => $id,
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveTypeId,
            'tanggal_mulai' => $startDate,
            'tanggal_selesai' => $endDate,
            'jumlah_hari_kerja' => $workdays,
            'alasan' => 'Pengajuan final legacy untuk cutover fakta.',
            'status' => 'disetujui',
            'created_at' => Carbon::parse('2026-01-01'),
            'updated_at' => Carbon::parse('2026-01-02'),
        ]);

        return LeaveRequest::query()->findOrFail($id);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function effectSnapshot(): array
    {
        return [
            'facts' => $this->orderedRows('leave_usage_records'),
            'ledger' => $this->orderedRows('leave_balance_ledger'),
            'balances' => $this->orderedRows('leave_balances'),
            'audits' => $this->orderedRows('audit_logs'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function orderedRows(string $table): array
    {
        return DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
