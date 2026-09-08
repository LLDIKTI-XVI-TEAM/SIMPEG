<?php

namespace Tests\Feature;

use App\Actions\Cuti\ReconcileAnnualLeaveAnniversaryAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\RefJenisPegawai;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use App\Services\Cuti\LeaveBalanceService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnnualLeaveAnniversaryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Menangkap projection nol yang tetap stale ketika anniversary terlewat tanpa proses mutasi terjadwal.
     */
    public function test_reconciler_memulihkan_projection_nol_setelah_anniversary_terlewat(): void
    {
        $employee = $this->employeeWithZeroProjectionBeforeAnniversary('2025-08-23');

        Carbon::setTestNow('2026-08-25 00:20:00');

        $this->assertSame(0, LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2026)
            ->value('jatah_awal'));
        $this->assertArrayHasKey('cuti:reconcile-anniversary-entitlements', Artisan::all());

        $this->artisan('cuti:reconcile-anniversary-entitlements --limit=50')
            ->assertExitCode(0);

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'sisa_tahun_berjalan' => 12,
            'sisa' => 12,
        ]);
    }

    public function test_retry_reconciler_tidak_menduplikasi_ledger_atau_audit(): void
    {
        $employee = $this->employeeWithZeroProjectionBeforeAnniversary('2025-08-23');
        Carbon::setTestNow('2026-08-25 00:20:00');

        $this->runReconciler();
        $afterFirstRun = $this->effectCounts($employee);
        $this->runReconciler();

        $this->assertSame($afterFirstRun, $this->effectCounts($employee));
        $this->assertSame(12, LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2026)
            ->value('jatah_awal'));
    }

    public function test_recovery_anniversary_terlewat_membentuk_carry_saat_tahun_sudah_berganti(): void
    {
        $employee = $this->employeeWithAppointment('2025-12-31');
        Carbon::setTestNow('2026-12-30 10:00:00');
        app(LeaveBalanceRecalculationService::class)->recalculateForSystem(
            $employee,
            2026,
            'Baseline sebelum anniversary.',
            'SIMPEG Scheduler',
        );
        $this->assertSame(0, $employee->leaveBalances()->where('tahun', 2026)->sole()->jatah_awal);
        Carbon::setTestNow('2027-01-01 00:20:00');

        $this->runReconciler();

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2027,
            'jatah_awal' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 6,
            'sisa_tahun_berjalan' => 12,
            'sisa' => 18,
        ]);
        $this->assertSame(0, $employee->leaveUsageRecords()->count());
    }

    public function test_reconciler_membuat_projection_pegawai_eligible_tanpa_baseline_tahun_berjalan(): void
    {
        Carbon::setTestNow('2026-08-25 00:20:00');
        $employee = $this->employeeWithAppointment('2020-01-01');

        $this->runReconciler();

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'sisa_tahun_berjalan' => 12,
            'sisa_n2' => 6,
            'sisa_n1' => 6,
            'sisa' => 24,
        ]);
        $this->assertSame(24, app(LeaveBalanceService::class)->availableFor($employee, 2026));
    }

    /**
     * Tahun saldo mengikuti tanggal bisnis WITA meski proses aplikasi masih berada pada 31 Desember UTC.
     */
    public function test_rekalkulasi_membentuk_projection_tahun_baru_wita_saat_timezone_aplikasi_utc(): void
    {
        $originalPhpTimezone = $this->freezeUtcApplicationAtNewYearWita();

        try {
            $employee = $this->employeeWithAppointment('2020-01-01');

            app(LeaveBalanceRecalculationService::class)->recalculateForSystem(
                $employee,
                2026,
                'Membentuk projection tahun baru berdasarkan tanggal bisnis WITA.',
                'SIMPEG Scheduler',
            );

            $this->assertDatabaseHas('leave_balances', [
                'employee_id' => $employee->id,
                'tahun' => 2026,
                'jatah_awal' => 12,
                'sisa_tahun_berjalan' => 12,
                'sisa' => 24,
            ]);
        } finally {
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    public function test_reconciler_tidak_membuat_projection_pegawai_belum_eligible_tanpa_baseline(): void
    {
        Carbon::setTestNow('2026-08-25 00:20:00');
        $employee = $this->employeeWithAppointment('2025-08-26');
        $before = $this->effectCounts($employee);

        $this->runReconciler();

        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2026,
        ]);
        $this->assertSame($before, $this->effectCounts($employee));
    }

    public function test_reconciler_membiarkan_projection_pegawai_yang_belum_eligible(): void
    {
        $employee = $this->employeeWithZeroProjectionBeforeAnniversary('2025-08-26');
        Carbon::setTestNow('2026-08-25 00:20:00');
        $before = $this->effectCounts($employee);

        $this->runReconciler();

        $this->assertSame($before, $this->effectCounts($employee));
        $this->assertSame(0, LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2026)
            ->value('jatah_awal'));
    }

    public function test_kegagalan_audit_merollback_projection_dan_ledger_replay(): void
    {
        $employee = $this->employeeWithZeroProjectionBeforeAnniversary('2025-08-23');
        Carbon::setTestNow('2026-08-25 00:20:00');
        $before = $this->effectCounts($employee);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_anniversary_audit() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'anniversary audit intentionally rejected';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER reject_anniversary_audit_trigger
BEFORE INSERT ON audit_logs
FOR EACH ROW EXECUTE FUNCTION reject_anniversary_audit();
SQL);

        try {
            $this->artisan('cuti:reconcile-anniversary-entitlements --limit=50')
                ->expectsOutputToContain('Berhasil: 0, gagal: 1.')
                ->assertExitCode(1);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_anniversary_audit_trigger ON audit_logs; DROP FUNCTION IF EXISTS reject_anniversary_audit();');
        }

        $this->assertSame($before, $this->effectCounts($employee));
        $this->assertSame(0, LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2026)
            ->value('jatah_awal'));
    }

    public function test_limit_hanya_dipakai_oleh_kandidat_eligible_dan_tidak_terhalang_kandidat_belum_eligible(): void
    {
        Carbon::setTestNow('2026-08-25 00:20:00');
        $ineligible = collect([
            $this->employeeWithAppointment('2025-08-26'),
            $this->employeeWithAppointment('2025-09-01'),
        ]);
        $eligible = collect([
            $this->employeeWithAppointment('2025-08-23'),
            $this->employeeWithAppointment('2024-02-29'),
            $this->employeeWithAppointment('2020-01-01'),
        ]);

        $ineligible->concat($eligible)->values()->each(function (Employee $employee, int $index): void {
            $this->createZeroBalance($employee, sprintf('00000000-0000-4000-8000-%012d', $index + 1));
        });

        $this->runReconciler(2);

        $this->assertSame(2, LeaveBalance::query()
            ->whereIn('employee_id', $eligible->pluck('id'))
            ->where('tahun', 2026)
            ->where('jatah_awal', 12)
            ->count());
        $this->assertSame(1, LeaveBalance::query()
            ->whereIn('employee_id', $eligible->pluck('id'))
            ->where('tahun', 2026)
            ->where('jatah_awal', 0)
            ->count());
        $this->assertSame(2, LeaveBalance::query()
            ->whereIn('employee_id', $ineligible->pluck('id'))
            ->where('tahun', 2026)
            ->where('jatah_awal', 0)
            ->count());
    }

    public function test_kegagalan_kandidat_tidak_menghentikan_window_dan_cursor_melanjutkan_kandidat_berikutnya(): void
    {
        $failed = $this->employeeWithZeroProjectionBeforeAnniversary(
            '2025-08-23',
            '00000000-0000-4000-8000-000000000101',
        );
        $withinWindow = $this->employeeWithZeroProjectionBeforeAnniversary(
            '2025-08-23',
            '00000000-0000-4000-8000-000000000102',
        );
        $beyondWindow = $this->employeeWithZeroProjectionBeforeAnniversary(
            '2025-08-23',
            '00000000-0000-4000-8000-000000000103',
        );
        Carbon::setTestNow('2026-08-25 00:20:00');
        $failedBalance = LeaveBalance::query()->whereBelongsTo($failed)->where('tahun', 2026)->sole();
        $failedBefore = $failedBalance->getAttributes();
        $failedEffectsBefore = $this->effectCounts($failed);

        $this->rejectAuditForBalance($failedBalance->id);

        try {
            $first = app(ReconcileAnnualLeaveAnniversaryAction::class)->execute(
                Carbon::now('Asia/Makassar'),
                2,
            );

            $this->assertSame(2, $first['attempted']);
            $this->assertSame(1, $first['processed']);
            $this->assertSame(1, $first['failed']);
            $this->assertCount(1, $first['failures']);
            $failure = $first['failures'][0];
            $this->assertSame($failed->id, $failure['employee_id']);
            $this->assertSame('2026-08-25', $failure['business_date']);
            $this->assertSame(QueryException::class, $failure['exception_class']);
            $this->assertTrue(Str::isUuid($failure['correlation_id']));
            $this->assertArrayNotHasKey('message', $failure);
            $this->assertSame($withinWindow->id, DB::table('annual_leave_anniversary_scheduler_states')
                ->where('scheduler_key', 'annual_leave_entitlement')
                ->value('cursor_employee_id'));

            $this->assertSame($failedBefore, $failedBalance->fresh()->getAttributes());
            $this->assertSame($failedEffectsBefore, $this->effectCounts($failed));
            $this->assertSame(12, LeaveBalance::query()->whereBelongsTo($withinWindow)->where('tahun', 2026)->value('jatah_awal'));
            $this->assertSame(0, LeaveBalance::query()->whereBelongsTo($beyondWindow)->where('tahun', 2026)->value('jatah_awal'));

            $second = app(ReconcileAnnualLeaveAnniversaryAction::class)->execute(
                Carbon::now('Asia/Makassar'),
                1,
            );

            $this->assertSame([
                'attempted' => 1,
                'processed' => 1,
                'failed' => 0,
                'failures' => [],
            ], $second);
            $this->assertSame(12, LeaveBalance::query()->whereBelongsTo($beyondWindow)->where('tahun', 2026)->value('jatah_awal'));
            $this->assertSame(0, LeaveBalance::query()->whereBelongsTo($failed)->where('tahun', 2026)->value('jatah_awal'));
            $this->assertSame($beyondWindow->id, DB::table('annual_leave_anniversary_scheduler_states')
                ->where('scheduler_key', 'annual_leave_entitlement')
                ->value('cursor_employee_id'));
        } finally {
            $this->restoreAnniversaryAuditTrigger();
        }
    }

    public function test_cursor_wrap_memproses_awal_urutan_tanpa_mencoba_kandidat_gagal_dua_kali_dalam_run(): void
    {
        $beginning = $this->employeeWithZeroProjectionBeforeAnniversary(
            '2025-08-23',
            '00000000-0000-4000-8000-000000000201',
        );
        $cursorEmployee = $this->employeeWithZeroProjectionBeforeAnniversary(
            '2025-08-23',
            '00000000-0000-4000-8000-000000000202',
        );
        $afterCursor = $this->employeeWithZeroProjectionBeforeAnniversary(
            '2025-08-23',
            '00000000-0000-4000-8000-000000000203',
        );
        Carbon::setTestNow('2026-08-25 00:20:00');
        DB::table('annual_leave_anniversary_scheduler_states')
            ->where('scheduler_key', 'annual_leave_entitlement')
            ->update([
                'cursor_employee_id' => $cursorEmployee->id,
                'cursor_advanced_at' => now(),
                'updated_at' => now(),
            ]);
        $afterCursorBalance = LeaveBalance::query()->whereBelongsTo($afterCursor)->where('tahun', 2026)->sole();
        $this->rejectAuditForBalance($afterCursorBalance->id);

        try {
            $result = app(ReconcileAnnualLeaveAnniversaryAction::class)->execute(
                Carbon::now('Asia/Makassar'),
                3,
            );

            $this->assertSame(3, $result['attempted']);
            $this->assertSame(2, $result['processed']);
            $this->assertSame(1, $result['failed']);
            $this->assertCount(1, $result['failures']);
            $this->assertSame($afterCursor->id, $result['failures'][0]['employee_id']);
            $this->assertSame(12, LeaveBalance::query()->whereBelongsTo($beginning)->where('tahun', 2026)->value('jatah_awal'));
            $this->assertSame(12, LeaveBalance::query()->whereBelongsTo($cursorEmployee)->where('tahun', 2026)->value('jatah_awal'));
            $this->assertSame(0, LeaveBalance::query()->whereBelongsTo($afterCursor)->where('tahun', 2026)->value('jatah_awal'));
            $this->assertSame($cursorEmployee->id, DB::table('annual_leave_anniversary_scheduler_states')
                ->where('scheduler_key', 'annual_leave_entitlement')
                ->value('cursor_employee_id'));
        } finally {
            $this->restoreAnniversaryAuditTrigger();
        }
    }

    public function test_filter_kandidat_mengikuti_anniversary_no_overflow_tmt_29_februari(): void
    {
        Carbon::setTestNow('2025-02-27 10:00:00');
        $employee = $this->employeeWithAppointment('2024-02-29');
        app(LeaveBalanceRecalculationService::class)->recalculateForSystem(
            $employee,
            2025,
            'Membentuk baseline leap-day sebelum anniversary.',
            'SIMPEG Scheduler',
        );
        $this->assertSame(0, LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2025)
            ->value('jatah_awal'));

        Carbon::setTestNow('2025-02-28 00:20:00');
        $this->runReconciler();

        $this->assertSame(12, LeaveBalance::query()
            ->whereBelongsTo($employee)
            ->where('tahun', 2025)
            ->value('jatah_awal'));
    }

    public function test_scheduler_harian_memakai_wita_dan_proteksi_overlap(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains(
                (string) ($event->command ?? ''),
                'cuti:reconcile-anniversary-entitlements',
            ));

        $this->assertNotNull($event);
        $this->assertSame('15 0 * * *', $event->expression);
        $this->assertSame('Asia/Makassar', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(30, $event->expiresAt);
    }

    /**
     * Run anniversary pada 00:15 WITA harus membuka hak tahun baru walau aplikasi memakai UTC.
     */
    public function test_scheduler_anniversary_0015_wita_membentuk_saldo_tahun_baru_saat_aplikasi_utc(): void
    {
        $originalPhpTimezone = $this->freezeUtcApplicationAtNewYearWita();

        try {
            $employee = $this->employeeWithAppointment('2020-01-01');
            $event = collect(Schedule::events())
                ->first(fn ($event): bool => str_contains(
                    (string) ($event->command ?? ''),
                    'cuti:reconcile-anniversary-entitlements',
                ));

            $this->assertNotNull($event);
            $this->assertTrue($event->isDue($this->app));

            $this->runReconciler();

            $this->assertDatabaseHas('leave_balances', [
                'employee_id' => $employee->id,
                'tahun' => 2026,
                'jatah_awal' => 12,
                'sisa_tahun_berjalan' => 12,
                'sisa' => 24,
            ]);
        } finally {
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    /**
     * TMT tepat satu tahun harus memenuhi anniversary berdasarkan tanggal bisnis WITA, bukan instant UTC.
     */
    public function test_scheduler_anniversary_0015_wita_memberi_hak_pada_tmt_tepat_satu_tahun(): void
    {
        $originalPhpTimezone = $this->freezeUtcApplicationAtNewYearWita();

        try {
            $employee = $this->employeeWithAppointment('2025-01-01');

            $this->runReconciler();

            $this->assertDatabaseHas('leave_balances', [
                'employee_id' => $employee->id,
                'tahun' => 2026,
                'jatah_awal' => 12,
                'sisa_tahun_berjalan' => 12,
                'sisa' => 12,
            ]);
            $this->assertSame(12, app(LeaveBalanceService::class)->availableFor($employee, 2026));
        } finally {
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    private function employeeWithZeroProjectionBeforeAnniversary(
        string $tmt,
        ?string $employeeId = null,
    ): Employee {
        Carbon::setTestNow('2026-08-22 10:00:00');
        $employee = $this->employeeWithAppointment($tmt, $employeeId);

        app(LeaveBalanceRecalculationService::class)->recalculateForSystem(
            $employee,
            2026,
            'Membentuk baseline sebelum anniversary untuk pengujian.',
            'SIMPEG Scheduler',
        );

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 0,
            'sisa' => 0,
        ]);

        return $employee;
    }

    private function employeeWithAppointment(string $tmt, ?string $employeeId = null): Employee
    {
        $employee = Employee::factory()->create([
            'id' => $employeeId ?? (string) Str::uuid(),
            'jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->value('id'),
        ]);
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-ANNIVERSARY-'.$employee->id,
            'tanggal_sk' => $tmt,
        ]);

        return $employee;
    }

    private function createZeroBalance(Employee $employee, string $id): LeaveBalance
    {
        $balance = new LeaveBalance([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 0,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 0,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 0,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);
        $balance->id = $id;
        $balance->save();

        return $balance;
    }

    /** Membekukan instant 1 Januari 00:15 WITA sebagai 31 Desember 16:15 UTC. */
    private function freezeUtcApplicationAtNewYearWita(): string
    {
        $originalPhpTimezone = date_default_timezone_get();

        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::create(2025, 12, 31, 16, 15, 0, 'UTC'));

        return $originalPhpTimezone;
    }

    /** @return array{ledger:int,audit:int} */
    private function effectCounts(Employee $employee): array
    {
        $balanceIds = LeaveBalance::query()->whereBelongsTo($employee)->pluck('id');

        return [
            'ledger' => LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count(),
            'audit' => AuditLog::query()
                ->where('auditable_type', 'LeaveBalance')
                ->whereIn('auditable_id', $balanceIds)
                ->count(),
        ];
    }

    private function rejectAuditForBalance(string $balanceId): void
    {
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION reject_selected_anniversary_audit() RETURNS trigger AS \$\$
BEGIN
    IF NEW.auditable_type = 'LeaveBalance' AND NEW.auditable_id = '{$balanceId}' THEN
        RAISE EXCEPTION 'anniversary audit intentionally rejected for selected balance';
    END IF;

    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql;
CREATE TRIGGER reject_selected_anniversary_audit_trigger
BEFORE INSERT ON audit_logs
FOR EACH ROW EXECUTE FUNCTION reject_selected_anniversary_audit();
SQL);
    }

    private function restoreAnniversaryAuditTrigger(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS reject_selected_anniversary_audit_trigger ON audit_logs; DROP FUNCTION IF EXISTS reject_selected_anniversary_audit();');
    }

    private function runReconciler(int $limit = 50): void
    {
        $this->assertArrayHasKey('cuti:reconcile-anniversary-entitlements', Artisan::all());
        $this->artisan("cuti:reconcile-anniversary-entitlements --limit={$limit}")
            ->assertExitCode(0);
    }
}
