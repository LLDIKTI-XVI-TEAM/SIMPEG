<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GuardsDestructiveMigrationTestEnvironment;
use Tests\TestCase;
use Throwable;

/**
 * Fixture ini menjalankan jalur upgrade nyata pada database test standar setelah full suite
 * selesai agar histori ledger lama dapat dibedakan dari event writer yang tetap aktif.
 */
#[Group('guarded-destructive')]
class LegacyLeaveBalanceLedgerCutoverMigrationTest extends TestCase
{
    use GuardsDestructiveMigrationTestEnvironment;

    private const DESTRUCTIVE_MIGRATION_OPT_IN = 'SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS';

    private const STANDARD_TEST_DATABASE = 'simpeg_test';

    private const CUTOVER_MIGRATION = 'database/migrations/2026_08_18_000007_remove_legacy_leave_balance_adjust_permission.php';

    private const BACKFILL_MIGRATION = 'database/migrations/2026_08_23_000003_backfill_legacy_approved_leave_usage.php';

    private const WRITER_CONSTRAINT = 'leave_balance_ledger_active_event_type_write_check';

    /**
     * Kontrak literal ini sengaja tidak mengambil nilai dari model atau migration agar test
     * menangkap event aktif yang terhapus atau bertambah tanpa keputusan contract.
     *
     * @var list<string>
     */
    private const ACTIVE_EVENTS = [
        'annual_entitlement_granted',
        'balance_recalculated',
        'carry_over_expired',
        'carry_over_granted',
        'duty_postponement_recorded',
        'rollover_applied',
        'usage_fact_cancelled',
        'usage_fact_recorded',
        'usage_fact_superseded',
    ];

    /** @var list<string> */
    private const LEGACY_EVENTS = [
        'leave_deducted',
        'manual_adjustment',
        'opening_balance_set',
    ];

    public function test_upgrade_mempertahankan_histori_legacy_tetapi_memblokir_writer_legacy_baru(): void
    {
        $this->requireStandardDestructiveTestDatabase();
        Carbon::setTestNow('2026-08-24 09:00:00');

        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', [
                '--path' => $this->migrationPathsBeforeCutover(),
                '--force' => true,
            ]), Artisan::output());

            [$employee, $request] = $this->legacyApprovedAnnualFixture();
            [$adminRole, $superRole, $legacyPermission] = $this->legacyPermissionFixture();
            $this->insertLegacyLedgerFixture($employee, $request);
            $legacyBefore = $this->legacyLedgerSnapshot();

            $this->assertSame([
                [
                    'id' => '30000000-0000-4000-8000-000000000001',
                    'event_type' => 'leave_deducted',
                    'amount' => -2,
                    'source_year' => 2026,
                    'reason' => 'Pemotongan final legacy.',
                ],
                [
                    'id' => '30000000-0000-4000-8000-000000000002',
                    'event_type' => 'manual_adjustment',
                    'amount' => 3,
                    'source_year' => 2026,
                    'reason' => 'Koreksi manual legacy.',
                ],
                [
                    'id' => '30000000-0000-4000-8000-000000000003',
                    'event_type' => 'opening_balance_set',
                    'amount' => 12,
                    'source_year' => 2026,
                    'reason' => 'Saldo pembuka legacy.',
                ],
            ], $this->legacyLedgerCoreSnapshot());

            try {
                $exitCode = Artisan::call('migrate', ['--force' => true]);
            } catch (Throwable $exception) {
                $this->fail('Upgrade normal harus menerima histori ledger legacy: '.$exception->getMessage());
            }

            $this->assertSame(0, $exitCode, Artisan::output());
            $this->assertSame($legacyBefore, $this->legacyLedgerSnapshot());
            $this->assertSame(3, DB::table('leave_balance_ledger')
                ->whereIn('event_type', self::LEGACY_EVENTS)
                ->count());

            $this->assertDatabaseMissing('permissions', ['id' => $legacyPermission->id]);
            $this->assertDatabaseMissing('role_permissions', ['permission_id' => $legacyPermission->id]);

            $this->assertSame(1, LeaveUsageRecord::query()
                ->where('leave_request_id', $request->id)
                ->count());
            $fact = LeaveUsageRecord::query()->where('leave_request_id', $request->id)->sole();
            $this->assertSame('approved_request', $fact->source_type);
            $this->assertSame(2, $fact->workdays);
            $this->assertSame(1, LeaveBalanceLedger::query()
                ->where('leave_request_id', $request->id)
                ->where('event_type', 'usage_fact_recorded')
                ->count());

            $balance = LeaveBalance::query()
                ->where('employee_id', $employee->id)
                ->where('tahun', 2026)
                ->sole();
            $this->assertSame([
                'jatah_awal' => 12,
                'carry_over' => 0,
                'terpakai' => 2,
                'sisa' => 10,
                'sisa_n2' => 0,
                'sisa_n1' => 0,
                'sisa_tahun_berjalan' => 10,
                'terpakai_tahun_berjalan' => 2,
                'hangus' => 0,
            ], collect($balance->only([
                'jatah_awal',
                'carry_over',
                'terpakai',
                'sisa',
                'sisa_n2',
                'sisa_n1',
                'sisa_tahun_berjalan',
                'terpakai_tahun_berjalan',
                'hangus',
            ]))->map(fn (mixed $value): int => (int) $value)->all());

            $historicalConstraint = $this->constraint('leave_balance_ledger_event_type_check');
            $this->assertSame(1, (int) $historicalConstraint->validated);
            $this->assertStringContainsString('leave_deducted', $historicalConstraint->definition);
            $this->assertStringContainsString('manual_adjustment', $historicalConstraint->definition);
            $this->assertStringContainsString('opening_balance_set', $historicalConstraint->definition);

            $this->assertWriterConstraintContract($employee, 'setelah-cutover');

            $this->assertLegacyHistoryRemainsAppendOnly();

            $stateBeforeRetry = $this->effectSnapshot();
            DB::table('migrations')->whereIn('migration', [
                pathinfo(self::CUTOVER_MIGRATION, PATHINFO_FILENAME),
                pathinfo(self::BACKFILL_MIGRATION, PATHINFO_FILENAME),
            ])->delete();
            $this->assertSame(0, Artisan::call('migrate', [
                '--path' => [self::CUTOVER_MIGRATION, self::BACKFILL_MIGRATION],
                '--force' => true,
            ]), Artisan::output());
            $this->assertSame($stateBeforeRetry, $this->effectSnapshot());
            $this->assertSame($legacyBefore, $this->legacyLedgerSnapshot());
            $this->assertWriterConstraintContract($employee, 'setelah-rerun');

            $migration = require database_path('migrations/2026_08_18_000007_remove_legacy_leave_balance_adjust_permission.php');
            $migration->down();

            $this->assertNull($this->constraintOrNull(self::WRITER_CONSTRAINT));
            $restoredPermission = Permission::query()->where('name', 'cuti.balance.adjust')->sole();
            $this->assertDatabaseHas('role_permissions', [
                'role_id' => $adminRole->id,
                'permission_id' => $restoredPermission->id,
            ]);
            $this->assertDatabaseMissing('role_permissions', [
                'role_id' => $superRole->id,
                'permission_id' => $restoredPermission->id,
            ]);

            DB::table('leave_balance_ledger')->insert([
                'id' => '30000000-0000-4000-8000-000000000020',
                'employee_id' => $employee->id,
                'tahun' => 2026,
                'event_type' => 'manual_adjustment',
                'amount' => 1,
                'source_year' => 2026,
                'reason' => 'Fixture writer legacy setelah rollback.',
                'dedup_key' => 'fixture:legacy:event:after-rollback',
                'occurred_at' => '2026-08-24 09:00:00',
                'created_at' => '2026-08-24 09:00:00',
                'updated_at' => '2026-08-24 09:00:00',
            ]);
            $this->assertDatabaseHas('leave_balance_ledger', [
                'id' => '30000000-0000-4000-8000-000000000020',
                'event_type' => 'manual_adjustment',
            ]);
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
            $this->markTestSkipped('Set SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS=true untuk menjalankan upgrade ledger destruktif.');
        }

        $environment = app()->environment();
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        if ($environment !== 'testing' || $driver !== 'pgsql' || $database !== self::STANDARD_TEST_DATABASE) {
            $this->fail(sprintf(
                'Upgrade ledger destruktif ditolak: wajib APP_ENV=testing, driver pgsql, dan database %s; aktual environment=%s, driver=%s, database=%s.',
                self::STANDARD_TEST_DATABASE,
                $environment,
                $driver,
                $database,
            ));
        }
    }

    /** @return list<string> */
    private function migrationPathsBeforeCutover(): array
    {
        $paths = glob(database_path('migrations/*.php')) ?: [];

        return collect($paths)
            ->sort()
            ->filter(fn (string $path): bool => pathinfo($path, PATHINFO_FILENAME)
                < pathinfo(self::CUTOVER_MIGRATION, PATHINFO_FILENAME))
            ->map(fn (string $path): string => 'database/migrations/'.basename($path))
            ->values()
            ->all();
    }

    /** @return array{Employee, LeaveRequest} */
    private function legacyApprovedAnnualFixture(): array
    {
        $annual = RefJenisCuti::query()->create([
            'code' => 'tahunan',
            'nama' => 'Cuti Tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $pns = RefJenisPegawai::query()->firstOrCreate(['nama' => 'PNS']);
        $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
        Appointment::query()->create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-FIXTURE-LEGACY-CUTOVER',
            'tanggal_sk' => '2020-01-01',
        ]);
        // Fixture pra-cutover hanya memakai kolom historis, bukan default model runtime terbaru.
        $requestId = (string) Str::uuid();
        DB::table('leave_requests')->insert([
            'id' => $requestId,
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $annual->id,
            'tanggal_mulai' => '2026-06-08',
            'tanggal_selesai' => '2026-06-09',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengajuan tahunan approved sebelum cutover.',
            'status' => 'disetujui',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$employee, LeaveRequest::query()->findOrFail($requestId)];
    }

    /** @return array{Role, Role, Permission} */
    private function legacyPermissionFixture(): array
    {
        $adminRole = Role::query()->create([
            'name' => 'admin_kepegawaian',
            'guard_name' => 'web',
            'description' => 'Fixture Admin Kepegawaian.',
        ]);
        $superRole = Role::query()->create([
            'name' => 'super_admin',
            'guard_name' => 'web',
            'description' => 'Fixture Super Admin.',
        ]);
        $permission = Permission::query()->create([
            'name' => 'cuti.balance.adjust',
            'module' => 'cuti',
            'description' => 'Permission direct-write saldo legacy.',
        ]);

        foreach ([$adminRole, $superRole] as $role) {
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => $permission->id,
                'created_at' => '2026-08-17 08:00:00',
                'updated_at' => '2026-08-17 08:00:00',
            ]);
        }

        return [$adminRole, $superRole, $permission];
    }

    private function insertLegacyLedgerFixture(Employee $employee, LeaveRequest $request): void
    {
        DB::table('leave_balance_ledger')->insert([
            [
                'id' => '30000000-0000-4000-8000-000000000001',
                'employee_id' => $employee->id,
                'leave_request_id' => $request->id,
                'tahun' => 2026,
                'event_type' => 'leave_deducted',
                'amount' => -2,
                'source_year' => 2026,
                'reason' => 'Pemotongan final legacy.',
                'dedup_key' => 'fixture:legacy:leave-deducted',
                'occurred_at' => '2026-06-10 09:00:00',
                'created_at' => '2026-06-10 09:00:00',
                'updated_at' => '2026-06-10 09:00:00',
            ],
            [
                'id' => '30000000-0000-4000-8000-000000000002',
                'employee_id' => $employee->id,
                'leave_request_id' => null,
                'tahun' => 2026,
                'event_type' => 'manual_adjustment',
                'amount' => 3,
                'source_year' => 2026,
                'reason' => 'Koreksi manual legacy.',
                'dedup_key' => 'fixture:legacy:manual-adjustment',
                'occurred_at' => '2026-06-11 09:00:00',
                'created_at' => '2026-06-11 09:00:00',
                'updated_at' => '2026-06-11 09:00:00',
            ],
            [
                'id' => '30000000-0000-4000-8000-000000000003',
                'employee_id' => $employee->id,
                'leave_request_id' => null,
                'tahun' => 2026,
                'event_type' => 'opening_balance_set',
                'amount' => 12,
                'source_year' => 2026,
                'reason' => 'Saldo pembuka legacy.',
                'dedup_key' => 'fixture:legacy:opening-balance',
                'occurred_at' => '2026-01-01 08:00:00',
                'created_at' => '2026-01-01 08:00:00',
                'updated_at' => '2026-01-01 08:00:00',
            ],
        ]);
    }

    private function assertRawLegacyInsertRejected(Employee $employee, string $event): void
    {
        try {
            DB::table('leave_balance_ledger')->insert([
                'id' => (string) Str::uuid(),
                'employee_id' => $employee->id,
                'tahun' => 2026,
                'event_type' => $event,
                'amount' => 1,
                'source_year' => 2026,
                'reason' => "Fixture writer legacy {$event} setelah cutover.",
                'created_at' => '2026-08-24 09:00:00',
                'updated_at' => '2026-08-24 09:00:00',
            ]);
            $this->fail("PostgreSQL seharusnya menolak writer legacy {$event} setelah cutover.");
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }
    }

    /**
     * Memastikan writer-only CHECK tetap persis sembilan event aktif, termasuk setelah rerun.
     */
    private function assertWriterConstraintContract(Employee $employee, string $phase): void
    {
        $constraint = $this->constraint(self::WRITER_CONSTRAINT);
        $this->assertSame(0, (int) $constraint->validated);
        $this->assertSame(self::ACTIVE_EVENTS, $this->constraintEventTypes((string) $constraint->definition));

        foreach (self::LEGACY_EVENTS as $event) {
            $this->assertRawLegacyInsertRejected($employee, $event);
        }

        foreach (self::ACTIVE_EVENTS as $event) {
            $this->assertRawActiveInsertAccepted($employee, $event, $phase);
        }
    }

    private function assertRawActiveInsertAccepted(Employee $employee, string $event, string $phase): void
    {
        $id = (string) Str::uuid();
        $dedupKey = "fixture:active:{$phase}:{$event}";

        DB::table('leave_balance_ledger')->insert([
            'id' => $id,
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'event_type' => $event,
            'amount' => 1,
            'source_year' => 2026,
            'reason' => "Fixture event aktif {$phase}.",
            'dedup_key' => $dedupKey,
            'occurred_at' => '2026-08-24 09:00:00',
            'created_at' => '2026-08-24 09:00:00',
            'updated_at' => '2026-08-24 09:00:00',
        ]);

        $this->assertDatabaseHas('leave_balance_ledger', [
            'id' => $id,
            'event_type' => $event,
            'dedup_key' => $dedupKey,
        ]);
    }

    /** @return list<string> */
    private function constraintEventTypes(string $definition): array
    {
        preg_match_all("/'([^']+)'/", $definition, $matches);
        $events = array_values($matches[1] ?? []);
        sort($events);

        return $events;
    }

    private function assertLegacyHistoryRemainsAppendOnly(): void
    {
        $legacyId = '30000000-0000-4000-8000-000000000001';

        foreach ([
            fn (): int => DB::table('leave_balance_ledger')->where('id', $legacyId)->update(['amount' => 99]),
            fn (): int => DB::table('leave_balance_ledger')->where('id', $legacyId)->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Trigger append-only seharusnya menolak mutasi histori ledger legacy.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', (string) $exception->getCode());
            }
        }

        $this->assertDatabaseHas('leave_balance_ledger', [
            'id' => $legacyId,
            'event_type' => 'leave_deducted',
            'amount' => -2,
        ]);
    }

    private function constraint(string $name): object
    {
        $constraint = $this->constraintOrNull($name);
        $this->assertNotNull($constraint, "Constraint {$name} wajib tersedia.");

        return $constraint;
    }

    private function constraintOrNull(string $name): ?object
    {
        return DB::selectOne(<<<'SQL'
SELECT CASE WHEN convalidated THEN 1 ELSE 0 END AS validated,
       pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = 'leave_balance_ledger'::regclass
  AND conname = ?
SQL, [$name]);
    }

    /** @return list<array<string, mixed>> */
    private function legacyLedgerCoreSnapshot(): array
    {
        return DB::table('leave_balance_ledger')
            ->whereIn('event_type', self::LEGACY_EVENTS)
            ->orderBy('id')
            ->get(['id', 'event_type', 'amount', 'source_year', 'reason'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'event_type' => (string) $row->event_type,
                'amount' => (int) $row->amount,
                'source_year' => (int) $row->source_year,
                'reason' => (string) $row->reason,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function legacyLedgerSnapshot(): array
    {
        return DB::table('leave_balance_ledger')
            ->whereIn('event_type', self::LEGACY_EVENTS)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function effectSnapshot(): array
    {
        return [
            'facts' => $this->orderedRows('leave_usage_records'),
            'ledger' => $this->orderedRows('leave_balance_ledger'),
            'balances' => $this->orderedRows('leave_balances'),
            'audits' => $this->orderedRows('audit_logs'),
            'permissions' => $this->orderedRows('permissions'),
            'pivots' => DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function orderedRows(string $table): array
    {
        return DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }
}
