<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\Cuti\LeaveUsageRecordService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Concerns\GuardsDestructiveMigrationTestEnvironment;
use Tests\TestCase;

#[Group('guarded-destructive')]
class LeaveUsageExternalApprovalMigrationPathTest extends TestCase
{
    use GuardsDestructiveMigrationTestEnvironment;

    private const DESTRUCTIVE_MIGRATION_OPT_IN = 'SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS';

    private const STANDARD_TEST_DATABASE = 'simpeg_test';

    private const MIGRATION = 'database/migrations/2026_08_21_000001_create_leave_usage_external_approval_steps_table.php';

    public function test_real_migration_path_mempertahankan_legacy_dan_mewajibkan_snapshot_baru(): void
    {
        $this->requireStandardDestructiveTestDatabase();

        $this->assertSame(
            self::STANDARD_TEST_DATABASE,
            DB::connection()->getDatabaseName(),
            'Test migration-path hanya boleh berjalan pada database test standar.',
        );
        $this->assertFileExists(base_path(self::MIGRATION));

        Artisan::call('migrate:fresh', ['--force' => true]);
        $migration = $this->migration();
        $this->invokeMigration($migration, 'down');

        $this->assertFalse(Schema::hasTable('leave_usage_external_approval_steps'));
        $this->assertFalse(Schema::hasColumn('leave_usage_records', 'approval_document_number'));

        [$employee, $actor] = $this->employeeAndActor();
        $legacyId = (string) Str::uuid();
        DB::table('leave_usage_records')->insert($this->manualFactPayload($employee, $actor, $legacyId));

        try {
            $this->invokeMigration($migration, 'up');

            $this->assertTrue(Schema::hasTable('leave_usage_external_approval_steps'));
            $this->assertTrue(Schema::hasColumn('leave_usage_records', 'approval_document_number'));
            $this->assertSame(0, DB::table('leave_usage_external_approval_steps')->where('leave_usage_record_id', $legacyId)->count());

            app(LeaveUsageRecordService::class)->cancel(
                LeaveUsageRecord::query()->findOrFail($legacyId),
                'Legacy tetap dapat dibatalkan.',
                $actor,
            );
            $this->assertSame(
                LeaveUsageRecord::STATUS_CANCELLED,
                DB::table('leave_usage_records')->where('id', $legacyId)->value('record_status'),
            );

            $this->assertQueryRejected(function () use ($employee, $actor): void {
                DB::table('leave_usage_records')->insert($this->manualFactPayload($employee, $actor));
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            }, 'snapshot persetujuan');

            $validId = (string) Str::uuid();
            DB::transaction(function () use ($employee, $actor, $validId): void {
                DB::table('leave_usage_records')->insert($this->manualFactPayload($employee, $actor, $validId));
                DB::table('leave_usage_external_approval_steps')->insert([
                    $this->externalStep($validId, 1, 'kepala_bagian', 'approved'),
                    $this->externalStep($validId, 2, 'pybmc', 'final_approved'),
                ]);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->assertSame(2, DB::table('leave_usage_external_approval_steps')->where('leave_usage_record_id', $validId)->count());

            $approvedId = $this->insertApprovedFact($employee, $actor);
            $this->assertQueryRejected(function () use ($approvedId): void {
                DB::table('leave_usage_external_approval_steps')->insert([
                    $this->externalStep($approvedId, 1, 'kepala_bagian', 'approved'),
                    $this->externalStep($approvedId, 2, 'pybmc', 'final_approved'),
                ]);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            }, 'manual_external');

            $this->assertQueryRejected(
                fn (): int => DB::table('leave_usage_records')->where('id', $validId)->update([
                    'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
                ]),
                'source_type',
            );
            $this->assertQueryRejected(
                fn (): int => DB::table('leave_usage_records')->where('id', $approvedId)->update([
                    'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
                ]),
                'source_type',
            );
        } finally {
            if (! Schema::hasTable('leave_usage_external_approval_steps')) {
                $this->invokeMigration($migration, 'up');
            }
        }
    }

    /** Menahan migrate:fresh kecuali jalur destruktif memakai database test standar. */
    private function requireStandardDestructiveTestDatabase(): void
    {
        $optIn = $_SERVER[self::DESTRUCTIVE_MIGRATION_OPT_IN]
            ?? $_ENV[self::DESTRUCTIVE_MIGRATION_OPT_IN]
            ?? getenv(self::DESTRUCTIVE_MIGRATION_OPT_IN);

        if ($optIn !== 'true') {
            $this->markTestSkipped('Set SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS=true untuk menjalankan migration-path approval eksternal.');
        }

        $environment = app()->environment();
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        if ($environment !== 'testing' || $driver !== 'pgsql' || $database !== self::STANDARD_TEST_DATABASE) {
            $this->fail(sprintf(
                'Migration-path approval eksternal ditolak: wajib APP_ENV=testing, driver pgsql, dan database %s; aktual environment=%s, driver=%s, database=%s.',
                self::STANDARD_TEST_DATABASE,
                $environment,
                $driver,
                $database,
            ));
        }
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path(self::MIGRATION);

        return $migration;
    }

    /** Menjalankan anonymous migration nyata tanpa mengasumsikan method pada base Migration. */
    private function invokeMigration(Migration $migration, string $method): void
    {
        (new ReflectionMethod($migration, $method))->invoke($migration);
    }

    /** @return array<string, mixed> */
    private function manualFactPayload(Employee $employee, User $actor, ?string $id = null): array
    {
        return [
            'id' => $id ?? (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => $this->leaveType()->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'leave_request_id' => null,
            'leave_request_case_id' => null,
            'usage_year' => 2026,
            'effective_date' => '2026-04-01',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-01',
            'workdays' => 1,
            'administrative_note' => 'Fixture migration path.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'replaces_id' => null,
            'correction_reason' => null,
            'recorded_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function externalStep(string $recordId, int $order, string $type, string $result): array
    {
        return [
            'id' => (string) Str::uuid(),
            'leave_usage_record_id' => $recordId,
            'step_order' => $order,
            'step_type' => $type,
            'approver_source' => 'external_official',
            'approver_employee_id' => null,
            'approver_name_snapshot' => "Pejabat {$order}",
            'approver_nip_snapshot' => null,
            'approver_position_snapshot' => 'Pejabat Penguji',
            'approver_institution_snapshot' => 'Instansi Penguji',
            'acted_on' => '2026-03-20',
            'result_code' => $result,
            'decision_note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function insertApprovedFact(Employee $employee, User $actor): string
    {
        $requestId = (string) Str::uuid();
        DB::table('leave_requests')->insert([
            'id' => $requestId,
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $this->leaveType()->id,
            'tanggal_mulai' => '2026-04-02',
            'tanggal_selesai' => '2026-04-02',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture approved migration path.',
            'status' => 'disetujui',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $recordId = (string) Str::uuid();
        DB::table('leave_usage_records')->insert(array_merge(
            $this->manualFactPayload($employee, $actor, $recordId),
            [
                'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
                'leave_request_id' => $requestId,
                'effective_date' => '2026-04-02',
                'start_date' => '2026-04-02',
                'end_date' => '2026-04-02',
            ],
        ));

        return $recordId;
    }

    /** @return array{Employee, User} */
    private function employeeAndActor(): array
    {
        return [Employee::factory()->create(), User::factory()->adminKepegawaian()->create()];
    }

    private function leaveType(): RefJenisCuti
    {
        return RefJenisCuti::query()->firstOrCreate(
            ['code' => 'snapshot-migration'],
            ['nama' => 'Cuti Snapshot Migration', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => false],
        );
    }

    private function assertQueryRejected(callable $operation, string $message): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Operasi migration-path seharusnya ditolak.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
