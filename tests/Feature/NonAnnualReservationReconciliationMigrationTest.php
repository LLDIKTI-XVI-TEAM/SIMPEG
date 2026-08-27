<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Services\AuditService;
use App\Services\Cuti\LeaveBalanceReservationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use RuntimeException;
use Tests\Concerns\GuardsDestructiveMigrationTestEnvironment;
use Tests\TestCase;

#[Group('guarded-destructive')]
class NonAnnualReservationReconciliationMigrationTest extends TestCase
{
    use GuardsDestructiveMigrationTestEnvironment;

    private const ANNUAL_FLAG_MIGRATION = '2026_08_23_000001_enforce_annual_leave_type_balance_flag.php';

    private const DESTRUCTIVE_MIGRATION_OPT_IN = 'SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS';

    private const STANDARD_TEST_DATABASE = 'simpeg_test';

    private const RECONCILIATION_MIGRATION = '2026_08_23_000004_reconcile_nonannual_active_reservations.php';

    private const UPGRADE_REASON = 'Reservasi aktif non-tahunan dinetralkan saat upgrade invariant Cuti Tahunan.';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_upgrade_mengompensasi_hanya_net_reservasi_aktif_non_tahunan_secara_auditabel_dan_idempoten(): void
    {
        $this->requireStandardDestructiveTestDatabase();
        Carbon::setTestNow('2026-08-23 10:00:00');
        Artisan::call('migrate:fresh', ['--force' => true]);

        $reconciliation = $this->migration(self::RECONCILIATION_MIGRATION);
        $annualFlag = $this->migration(self::ANNUAL_FLAG_MIGRATION);
        $this->invokeMigration($reconciliation, 'down');
        $this->invokeMigration($annualFlag, 'down');

        [$employee, $balance] = $this->employeeWithBalance();
        $annual = $this->leaveType('Cuti Tahunan', 'tahunan', true);
        $dirtyNonAnnual = $this->leaveType('Cuti Legacy Non Tahunan', 'sakit_legacy', true);
        $annualRequest = $this->leaveRequest($employee, $annual, 'menunggu_approval', 3);
        $dirtyRequest = $this->leaveRequest($employee, $dirtyNonAnnual, 'menunggu_approval', 20);
        $terminalRequest = $this->leaveRequest($employee, $dirtyNonAnnual, 'tidak_disetujui', 4);
        $zeroNetRequest = $this->leaveRequest($employee, $dirtyNonAnnual, 'perlu_perubahan', 7);
        $annualEventId = $this->reservationEvent($balance, $annualRequest, 'annual-active', 3);
        $dirtyEventId = $this->reservationEvent($balance, $dirtyRequest, 'dirty-active', 20);
        $terminalEventId = $this->reservationEvent($balance, $terminalRequest, 'dirty-terminal', 4);
        $zeroNetReservedId = $this->reservationEvent($balance, $zeroNetRequest, 'dirty-zero-net-reserved', 7);
        $zeroNetReleasedId = $this->reservationEvent(
            $balance,
            $zeroNetRequest,
            'dirty-zero-net-released',
            -7,
            LeaveBalanceReservationEvent::EVENT_RELEASED,
        );

        $this->invokeMigration($annualFlag, 'up');

        $this->assertFalse((bool) RefJenisCuti::query()->whereKey($dirtyNonAnnual->id)->value('mengurangi_saldo_tahunan'));
        $this->assertSame(20, $this->reservationNet($dirtyRequest));
        $this->assertSame(
            9,
            app(LeaveBalanceReservationService::class)->availableForSubmission(
                $employee,
                2026,
                Carbon::parse('2026-08-23'),
            ),
            'Pertahanan runtime wajib berlaku sebelum event legacy direkonsiliasi.',
        );

        $this->invokeMigration($reconciliation, 'up');

        $compensationKey = "leave_reservation:{$dirtyRequest->id}:released:nonannual_upgrade:2026";
        $compensation = DB::table('leave_balance_reservation_events')
            ->where('dedup_key', $compensationKey)
            ->sole();
        $metadata = json_decode((string) $compensation->metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(LeaveBalanceReservationEvent::EVENT_RELEASED, $compensation->event_type);
        $this->assertSame(-20, (int) $compensation->amount);
        $this->assertNull($compensation->created_by);
        $this->assertNull($compensation->leave_balance_id);
        $this->assertSame('nonannual_active_reservation_upgrade', $metadata['release_context'] ?? null);
        $this->assertSame(20, $metadata['released_days'] ?? null);
        $this->assertSame(0, $this->reservationNet($dirtyRequest));

        $audit = DB::table('audit_logs')
            ->where('event', 'LEAVE_BALANCE_RESERVATION_RELEASED')
            ->where('auditable_id', $compensation->id)
            ->sole();
        $oldValues = json_decode((string) $audit->old_values, true, 512, JSON_THROW_ON_ERROR);
        $newValues = json_decode((string) $audit->new_values, true, 512, JSON_THROW_ON_ERROR);
        $this->assertNull($audit->user_id);
        $this->assertSame(AuditService::SYSTEM_DATABASE_UPGRADE, $audit->user_name);
        $this->assertSame(20, $oldValues['allocated_days'] ?? null);
        $this->assertSame(0, $newValues['allocated_days'] ?? null);
        $this->assertSame('system', $newValues['actor_type'] ?? null);

        $this->assertReservationHistory($annualRequest, [$annualEventId], 3);
        $this->assertReservationHistory($terminalRequest, [$terminalEventId], 4);
        $this->assertReservationHistory($zeroNetRequest, [$zeroNetReservedId, $zeroNetReleasedId], 0);
        $this->assertDatabaseHas('leave_balance_reservation_events', ['id' => $dirtyEventId]);

        $eventCount = DB::table('leave_balance_reservation_events')->count();
        $auditCount = DB::table('audit_logs')->count();
        $this->invokeMigration($reconciliation, 'up');
        $this->assertSame($eventCount, DB::table('leave_balance_reservation_events')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());

        try {
            $this->invokeMigration($reconciliation, 'down');
            $this->fail('Rollback wajib berhenti ketika bukti kompensasi sudah tersimpan.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('bukti rekonsiliasi reservasi non-tahunan', $exception->getMessage());
        }

        $this->assertDatabaseHas('leave_balance_reservation_events', ['id' => $compensation->id]);
    }

    public function test_upgrade_menolak_orphan_audit_sebelum_mutasi_baru_dan_tidak_menduplikasi_bukti(): void
    {
        $reconciliation = $this->freshDatabaseBeforeReconciliation();
        [$employee, $balance] = $this->employeeWithBalance();
        $dirtyNonAnnual = $this->leaveType('Cuti Legacy Non Tahunan', 'sakit_legacy', false);
        $dirtyRequest = $this->leaveRequest($employee, $dirtyNonAnnual, 'menunggu_approval', 20);
        $dirtyEventId = $this->reservationEvent($balance, $dirtyRequest, 'orphan-audit-source', 20);
        $orphanEventId = (string) Str::uuid();
        $orphanAuditId = $this->upgradeAudit($dirtyRequest, $orphanEventId, 20);
        $eventCount = DB::table('leave_balance_reservation_events')->count();
        $auditCount = DB::table('audit_logs')->count();

        $this->assertMigrationFailsWithoutMutation(
            $reconciliation,
            'Audit kompensasi reservasi tidak memiliki event pasangan',
            $eventCount,
            $auditCount,
        );
        $this->assertMigrationFailsWithoutMutation(
            $reconciliation,
            'Audit kompensasi reservasi tidak memiliki event pasangan',
            $eventCount,
            $auditCount,
        );

        $this->assertDatabaseHas('leave_balance_reservation_events', ['id' => $dirtyEventId]);
        $this->assertDatabaseHas('audit_logs', ['id' => $orphanAuditId, 'auditable_id' => $orphanEventId]);
        $this->assertSame(20, $this->reservationNet($dirtyRequest));
        $this->assertDatabaseMissing('leave_balance_reservation_events', [
            'dedup_key' => "leave_reservation:{$dirtyRequest->id}:released:nonannual_upgrade:2026",
        ]);
    }

    public function test_upgrade_menolak_collision_namespace_yang_menolkan_net_tanpa_metadata_upgrade(): void
    {
        $reconciliation = $this->freshDatabaseBeforeReconciliation();
        [$employee, $balance] = $this->employeeWithBalance();
        $dirtyNonAnnual = $this->leaveType('Cuti Legacy Non Tahunan', 'sakit_legacy', false);
        $dirtyRequest = $this->leaveRequest($employee, $dirtyNonAnnual, 'menunggu_approval', 20);
        $this->reservationEvent($balance, $dirtyRequest, 'collision-source', 20);
        $collisionId = $this->reservationEvent(
            $balance,
            $dirtyRequest,
            'ignored-by-explicit-key',
            -20,
            LeaveBalanceReservationEvent::EVENT_RELEASED,
            dedupKey: "leave_reservation:{$dirtyRequest->id}:released:nonannual_upgrade:2026",
            metadata: ['source' => 'database_upgrade'],
            associateBalance: false,
        );
        $eventCount = DB::table('leave_balance_reservation_events')->count();
        $auditCount = DB::table('audit_logs')->count();

        $this->assertMigrationFailsWithoutMutation(
            $reconciliation,
            'tidak memenuhi kontrak upgrade',
            $eventCount,
            $auditCount,
        );

        $this->assertDatabaseHas('leave_balance_reservation_events', ['id' => $collisionId]);
        $this->assertSame(0, $this->reservationNet($dirtyRequest));
    }

    public function test_upgrade_menolak_bukti_over_release_yang_tampak_lengkap_tetapi_net_negatif(): void
    {
        $reconciliation = $this->freshDatabaseBeforeReconciliation();
        [$employee, $balance] = $this->employeeWithBalance();
        $dirtyNonAnnual = $this->leaveType('Cuti Legacy Non Tahunan', 'sakit_legacy', false);
        $dirtyRequest = $this->leaveRequest($employee, $dirtyNonAnnual, 'menunggu_approval', 20);
        $this->reservationEvent($balance, $dirtyRequest, 'over-release-source', 20);
        $compensationId = $this->reservationEvent(
            $balance,
            $dirtyRequest,
            'ignored-by-explicit-key',
            -30,
            LeaveBalanceReservationEvent::EVENT_RELEASED,
            dedupKey: "leave_reservation:{$dirtyRequest->id}:released:nonannual_upgrade:2026",
            metadata: [
                'release_context' => 'nonannual_active_reservation_upgrade',
                'source' => 'database_upgrade',
                'released_days' => 30,
                'legacy_leave_type_code' => 'sakit_legacy',
            ],
            associateBalance: false,
            reason: self::UPGRADE_REASON,
        );
        $auditId = $this->upgradeAudit($dirtyRequest, $compensationId, 30);
        $eventCount = DB::table('leave_balance_reservation_events')->count();
        $auditCount = DB::table('audit_logs')->count();

        $this->assertMigrationFailsWithoutMutation(
            $reconciliation,
            'tidak menetralkan histori request-tahun secara tepat',
            $eventCount,
            $auditCount,
        );

        $this->assertDatabaseHas('leave_balance_reservation_events', ['id' => $compensationId]);
        $this->assertDatabaseHas('audit_logs', ['id' => $auditId]);
        $this->assertSame(-10, $this->reservationNet($dirtyRequest));
    }

    public function test_upgrade_menolak_audit_upgrade_dengan_reason_berbeda_dari_event(): void
    {
        $fixture = $this->existingNeutralizedUpgradeEvidence('sakit_legacy', 20);
        $auditId = $this->upgradeAudit(
            $fixture['request'],
            $fixture['compensation_id'],
            20,
            reason: 'Alasan audit palsu yang tidak sama dengan event.',
        );
        $eventCount = DB::table('leave_balance_reservation_events')->count();
        $auditCount = DB::table('audit_logs')->count();

        $this->assertMigrationFailsWithoutMutation(
            $fixture['migration'],
            'Audit kompensasi reservasi',
            $eventCount,
            $auditCount,
        );

        $this->assertDatabaseHas('audit_logs', ['id' => $auditId]);
    }

    public function test_upgrade_menolak_event_dan_audit_upgrade_dengan_reason_noncanonical_yang_sama(): void
    {
        $noncanonicalReason = 'Event dan audit konsisten, tetapi bukan reason resmi upgrade.';
        $fixture = $this->existingNeutralizedUpgradeEvidence(
            'sakit_legacy',
            20,
            $noncanonicalReason,
        );
        $auditId = $this->upgradeAudit(
            $fixture['request'],
            $fixture['compensation_id'],
            20,
            reason: $noncanonicalReason,
        );
        $eventCount = DB::table('leave_balance_reservation_events')->count();
        $auditCount = DB::table('audit_logs')->count();

        $this->assertMigrationFailsWithoutMutation(
            $fixture['migration'],
            'Bukti kompensasi reservasi',
            $eventCount,
            $auditCount,
        );

        $this->assertDatabaseHas('leave_balance_reservation_events', [
            'id' => $fixture['compensation_id'],
            'reason' => $noncanonicalReason,
        ]);
        $this->assertDatabaseHas('audit_logs', ['id' => $auditId]);
    }

    public function test_upgrade_menolak_audit_upgrade_dengan_legacy_code_berbeda_dari_metadata_event(): void
    {
        $fixture = $this->existingNeutralizedUpgradeEvidence('sakit_legacy', 20);
        $auditId = $this->upgradeAudit(
            $fixture['request'],
            $fixture['compensation_id'],
            20,
            legacyLeaveTypeCode: 'code_audit_palsu',
        );
        $eventCount = DB::table('leave_balance_reservation_events')->count();
        $auditCount = DB::table('audit_logs')->count();

        $this->assertMigrationFailsWithoutMutation(
            $fixture['migration'],
            'Audit kompensasi reservasi',
            $eventCount,
            $auditCount,
        );

        $this->assertDatabaseHas('audit_logs', ['id' => $auditId]);
    }

    public function test_upgrade_menolak_audit_upgrade_yang_menghilangkan_key_legacy_code_bernilai_null(): void
    {
        $fixture = $this->existingNeutralizedUpgradeEvidence(null, 6);
        $auditId = $this->upgradeAudit(
            $fixture['request'],
            $fixture['compensation_id'],
            6,
            legacyLeaveTypeCode: null,
            includeLegacyLeaveTypeCode: false,
        );
        $eventCount = DB::table('leave_balance_reservation_events')->count();
        $auditCount = DB::table('audit_logs')->count();

        $this->assertMigrationFailsWithoutMutation(
            $fixture['migration'],
            'Audit kompensasi reservasi',
            $eventCount,
            $auditCount,
        );

        $this->assertDatabaseHas('audit_logs', ['id' => $auditId]);
    }

    public function test_upgrade_menolak_event_legacy_dengan_employee_berbeda_dari_pemilik_request(): void
    {
        $reconciliation = $this->freshDatabaseBeforeReconciliation();
        [$owner, $balance] = $this->employeeWithBalance();
        $wrongEmployee = Employee::factory()->create();
        $dirtyNonAnnual = $this->leaveType('Cuti Legacy Non Tahunan', 'sakit_legacy', false);
        $dirtyRequest = $this->leaveRequest($owner, $dirtyNonAnnual, 'menunggu_approval', 20);
        $corruptEventId = $this->reservationEvent(
            $balance,
            $dirtyRequest,
            'wrong-employee',
            20,
            employeeId: $wrongEmployee->id,
        );
        $eventCount = DB::table('leave_balance_reservation_events')->count();
        $auditCount = DB::table('audit_logs')->count();

        $this->assertMigrationFailsWithoutMutation(
            $reconciliation,
            'employee event tidak sama dengan pemilik request',
            $eventCount,
            $auditCount,
        );

        $this->assertDatabaseHas('leave_balance_reservation_events', ['id' => $corruptEventId]);
        $this->assertSame(20, $this->reservationNet($dirtyRequest));
        $this->assertDatabaseMissing('leave_balance_reservation_events', [
            'dedup_key' => "leave_reservation:{$dirtyRequest->id}:released:nonannual_upgrade:2026",
        ]);
    }

    public function test_upgrade_menetralkan_net_multi_event_dengan_jumlah_kompensasi_yang_tepat(): void
    {
        $reconciliation = $this->freshDatabaseBeforeReconciliation();
        [$employee, $balance] = $this->employeeWithBalance();
        $dirtyNonAnnual = $this->leaveType('Cuti Legacy Non Tahunan', 'sakit_legacy', false);
        $dirtyRequest = $this->leaveRequest($employee, $dirtyNonAnnual, 'menunggu_approval', 20);
        $reservedId = $this->reservationEvent($balance, $dirtyRequest, 'multi-reserved', 20);
        $adjustedId = $this->reservationEvent(
            $balance,
            $dirtyRequest,
            'multi-adjusted',
            -5,
            LeaveBalanceReservationEvent::EVENT_ADJUSTED,
        );

        $this->invokeMigration($reconciliation, 'up');

        $compensation = DB::table('leave_balance_reservation_events')
            ->where('dedup_key', "leave_reservation:{$dirtyRequest->id}:released:nonannual_upgrade:2026")
            ->sole();
        $audit = DB::table('audit_logs')
            ->where('auditable_id', $compensation->id)
            ->sole();
        $oldValues = json_decode((string) $audit->old_values, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(-15, (int) $compensation->amount);
        $this->assertSame(15, $oldValues['allocated_days'] ?? null);
        $this->assertReservationHistory($dirtyRequest, [$reservedId, $adjustedId, $compensation->id], 0);
    }

    public function test_upgrade_memproses_lebih_dari_satu_batch_secara_idempoten(): void
    {
        $reconciliation = $this->freshDatabaseBeforeReconciliation();
        [$employee, $balance] = $this->employeeWithBalance();
        $dirtyNonAnnual = $this->leaveType('Cuti Legacy Non Tahunan', 'sakit_batch_legacy', false);
        $now = now();
        $requests = [];
        $events = [];

        for ($index = 0; $index < 201; $index++) {
            $requestId = (string) Str::uuid();
            $requests[] = [
                'id' => $requestId,
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $dirtyNonAnnual->id,
                'tanggal_mulai' => '2026-09-01',
                'tanggal_selesai' => '2026-09-01',
                'jumlah_hari_kerja' => 1,
                'alasan' => 'Fixture lintas batch rekonsiliasi.',
                'status' => 'menunggu_approval',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $events[] = [
                'id' => (string) Str::uuid(),
                'employee_id' => $employee->id,
                'leave_request_id' => $requestId,
                'leave_balance_id' => $balance->id,
                'tahun' => 2026,
                'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
                'amount' => 1,
                'reason' => 'Fixture lintas batch rekonsiliasi.',
                'dedup_key' => "test:nonannual-upgrade:batch:{$requestId}",
                'metadata' => json_encode(['source' => 'test_fixture'], JSON_THROW_ON_ERROR),
                'created_by' => null,
                'occurred_at' => $now,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($requests, 100) as $requestChunk) {
            DB::table('leave_requests')->insert($requestChunk);
        }
        foreach (array_chunk($events, 100) as $eventChunk) {
            DB::table('leave_balance_reservation_events')->insert($eventChunk);
        }

        $neutralizationQueryCount = 0;
        DB::connection()->beforeExecuting(static function (string $query) use (&$neutralizationQueryCount): void {
            if (str_contains(mb_strtolower($query), 'source_net_amount')) {
                $neutralizationQueryCount++;
            }
        });
        $this->invokeMigration($reconciliation, 'up');

        $this->assertSame(2, $neutralizationQueryCount, 'Verifikasi wajib satu aggregate query per batch 200 event.');
        $this->assertSame(201, DB::table('leave_balance_reservation_events')
            ->whereRaw("metadata->>'release_context' = ?", ['nonannual_active_reservation_upgrade'])
            ->count());
        $this->assertSame(201, DB::table('audit_logs')
            ->whereRaw("new_values->>'release_context' = ?", ['nonannual_active_reservation_upgrade'])
            ->count());
        $this->assertSame(0, (int) DB::table('leave_balance_reservation_events')
            ->where('employee_id', $employee->id)
            ->where('tahun', 2026)
            ->sum('amount'));

        $eventCount = DB::table('leave_balance_reservation_events')->count();
        $auditCount = DB::table('audit_logs')->count();
        $neutralizationQueryCount = 0;
        $this->invokeMigration($reconciliation, 'up');
        $this->assertSame(4, $neutralizationQueryCount, 'Preflight dan postflight rerun masing-masing tetap per batch.');
        $this->assertSame($eventCount, DB::table('leave_balance_reservation_events')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_upgrade_mempertahankan_provenance_null_untuk_jenis_legacy_tanpa_code(): void
    {
        $reconciliation = $this->freshDatabaseBeforeReconciliation();
        [$employee, $balance] = $this->employeeWithBalance();
        $legacyWithoutCode = RefJenisCuti::query()->create([
            'nama' => 'Cuti Legacy Tanpa Kode',
            'code' => null,
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $dirtyRequest = $this->leaveRequest($employee, $legacyWithoutCode, 'menunggu_approval', 6);
        $this->reservationEvent($balance, $dirtyRequest, 'null-code-source', 6);

        $this->invokeMigration($reconciliation, 'up');

        $compensation = DB::table('leave_balance_reservation_events')
            ->where('dedup_key', "leave_reservation:{$dirtyRequest->id}:released:nonannual_upgrade:2026")
            ->sole();
        $metadata = json_decode((string) $compensation->metadata, true, 512, JSON_THROW_ON_ERROR);
        $audit = DB::table('audit_logs')->where('auditable_id', $compensation->id)->sole();
        $oldValues = json_decode((string) $audit->old_values, true, 512, JSON_THROW_ON_ERROR);
        $newValues = json_decode((string) $audit->new_values, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('legacy_leave_type_code', $metadata);
        $this->assertNull($metadata['legacy_leave_type_code']);
        $this->assertArrayHasKey('legacy_leave_type_code', $oldValues);
        $this->assertNull($oldValues['legacy_leave_type_code']);
        $this->assertArrayHasKey('legacy_leave_type_code', $newValues);
        $this->assertNull($newValues['legacy_leave_type_code']);
        $this->assertSame(0, $this->reservationNet($dirtyRequest));

        $eventCount = DB::table('leave_balance_reservation_events')->count();
        $auditCount = DB::table('audit_logs')->count();
        $this->invokeMigration($reconciliation, 'up');
        $this->assertSame($eventCount, DB::table('leave_balance_reservation_events')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    /** Menahan migrate:fresh kecuali jalur destruktif memakai database test standar. */
    private function requireStandardDestructiveTestDatabase(): void
    {
        $optIn = $_SERVER[self::DESTRUCTIVE_MIGRATION_OPT_IN]
            ?? $_ENV[self::DESTRUCTIVE_MIGRATION_OPT_IN]
            ?? getenv(self::DESTRUCTIVE_MIGRATION_OPT_IN);

        if ($optIn !== 'true') {
            $this->markTestSkipped('Set SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS=true untuk menjalankan migration-path rekonsiliasi reservasi.');
        }

        $environment = app()->environment();
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        if ($environment !== 'testing' || $driver !== 'pgsql' || $database !== self::STANDARD_TEST_DATABASE) {
            $this->fail(sprintf(
                'Migration-path rekonsiliasi reservasi ditolak: wajib APP_ENV=testing, driver pgsql, dan database %s; aktual environment=%s, driver=%s, database=%s.',
                self::STANDARD_TEST_DATABASE,
                $environment,
                $driver,
                $database,
            ));
        }
    }

    /** @return array{Employee, LeaveBalance} */
    private function employeeWithBalance(): array
    {
        $employee = Employee::factory()->create();
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2024-01-01',
            'no_sk' => 'SK-MIGRASI-RESERVASI-001',
            'tanggal_sk' => '2024-01-01',
        ]);
        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);

        return [$employee, $balance];
    }

    private function leaveType(string $name, string $code, bool $reducesAnnualBalance): RefJenisCuti
    {
        return RefJenisCuti::create([
            'nama' => $name,
            'code' => $code,
            'mengurangi_saldo_tahunan' => $reducesAnnualBalance,
            'khusus_pns' => false,
        ]);
    }

    private function leaveRequest(
        Employee $employee,
        RefJenisCuti $leaveType,
        string $status,
        int $workdays,
    ): LeaveRequest {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-01',
            'jumlah_hari_kerja' => $workdays,
            'alasan' => 'Fixture migrasi reservasi non-tahunan.',
            'status' => $status,
        ]);
    }

    private function reservationEvent(
        LeaveBalance $balance,
        LeaveRequest $leaveRequest,
        string $suffix,
        int $amount,
        string $eventType = LeaveBalanceReservationEvent::EVENT_RESERVED,
        ?string $employeeId = null,
        ?string $dedupKey = null,
        ?array $metadata = null,
        bool $associateBalance = true,
        string $reason = 'Fixture dirty legacy reservation.',
    ): string {
        $id = (string) Str::uuid();
        DB::table('leave_balance_reservation_events')->insert([
            'id' => $id,
            'employee_id' => $employeeId ?? $leaveRequest->employee_id,
            'leave_request_id' => $leaveRequest->id,
            'leave_balance_id' => $associateBalance ? $balance->id : null,
            'tahun' => 2026,
            'event_type' => $eventType,
            'amount' => $amount,
            'reason' => $reason,
            'dedup_key' => $dedupKey ?? "test:nonannual-upgrade:{$suffix}:{$leaveRequest->id}",
            'metadata' => json_encode($metadata ?? ['source' => 'test_fixture'], JSON_THROW_ON_ERROR),
            'created_by' => null,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        return $id;
    }

    private function freshDatabaseBeforeReconciliation(): Migration
    {
        $this->requireStandardDestructiveTestDatabase();
        Carbon::setTestNow('2026-08-23 10:00:00');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $reconciliation = $this->migration(self::RECONCILIATION_MIGRATION);
        $this->invokeMigration($reconciliation, 'down');

        return $reconciliation;
    }

    /** @return array{migration:Migration,request:LeaveRequest,compensation_id:string} */
    private function existingNeutralizedUpgradeEvidence(
        ?string $leaveTypeCode,
        int $days,
        string $reason = self::UPGRADE_REASON,
    ): array {
        $migration = $this->freshDatabaseBeforeReconciliation();
        [$employee, $balance] = $this->employeeWithBalance();
        $leaveType = RefJenisCuti::query()->create([
            'nama' => 'Cuti Legacy Existing Evidence',
            'code' => $leaveTypeCode,
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $request = $this->leaveRequest($employee, $leaveType, 'menunggu_approval', $days);
        $this->reservationEvent($balance, $request, 'existing-evidence-source', $days);
        $compensationId = $this->reservationEvent(
            $balance,
            $request,
            'ignored-by-explicit-key',
            -$days,
            LeaveBalanceReservationEvent::EVENT_RELEASED,
            dedupKey: "leave_reservation:{$request->id}:released:nonannual_upgrade:2026",
            metadata: [
                'release_context' => 'nonannual_active_reservation_upgrade',
                'source' => 'database_upgrade',
                'released_days' => $days,
                'legacy_leave_type_code' => $leaveTypeCode,
            ],
            associateBalance: false,
            reason: $reason,
        );

        return [
            'migration' => $migration,
            'request' => $request,
            'compensation_id' => $compensationId,
        ];
    }

    private function upgradeAudit(
        LeaveRequest $leaveRequest,
        string $eventId,
        int $releasedDays,
        string $reason = self::UPGRADE_REASON,
        ?string $legacyLeaveTypeCode = 'sakit_legacy',
        bool $includeLegacyLeaveTypeCode = true,
    ): string {
        $auditId = (string) Str::uuid();
        $payload = [
            'employee_id' => $leaveRequest->employee_id,
            'leave_request_id' => $leaveRequest->id,
            'tahun' => 2026,
            'reservation_event_id' => $eventId,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RELEASED,
            'event_amount' => -$releasedDays,
            'reason' => $reason,
            'release_context' => 'nonannual_active_reservation_upgrade',
            'source' => 'database_upgrade',
        ];

        if ($includeLegacyLeaveTypeCode) {
            $payload['legacy_leave_type_code'] = $legacyLeaveTypeCode;
        }

        DB::table('audit_logs')->insert([
            'id' => $auditId,
            'user_id' => null,
            'user_name' => AuditService::SYSTEM_DATABASE_UPGRADE,
            'event' => 'LEAVE_BALANCE_RESERVATION_RELEASED',
            'auditable_type' => 'LeaveBalanceReservationEvent',
            'auditable_id' => $eventId,
            'old_values' => json_encode(array_merge($payload, ['allocated_days' => $releasedDays]), JSON_THROW_ON_ERROR),
            'new_values' => json_encode(array_merge($payload, [
                'allocated_days' => 0,
                'actor_type' => 'system',
            ]), JSON_THROW_ON_ERROR),
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => now(),
        ]);

        return $auditId;
    }

    private function assertMigrationFailsWithoutMutation(
        Migration $migration,
        string $expectedMessage,
        int $eventCount,
        int $auditCount,
    ): void {
        $exception = null;

        try {
            $this->invokeMigration($migration, 'up');
        } catch (RuntimeException $runtimeException) {
            $exception = $runtimeException;
        }

        if ($exception === null) {
            $this->fail('Migration wajib gagal tertutup sebelum menulis bukti baru.');
        }

        $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        $this->assertSame($eventCount, DB::table('leave_balance_reservation_events')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    private function reservationNet(LeaveRequest $leaveRequest): int
    {
        return (int) DB::table('leave_balance_reservation_events')
            ->where('leave_request_id', $leaveRequest->id)
            ->sum('amount');
    }

    /** @param list<string> $expectedIds */
    private function assertReservationHistory(LeaveRequest $leaveRequest, array $expectedIds, int $expectedNet): void
    {
        $events = DB::table('leave_balance_reservation_events')
            ->where('leave_request_id', $leaveRequest->id)
            ->orderBy('id')
            ->get(['id', 'amount']);

        $this->assertEqualsCanonicalizing($expectedIds, $events->pluck('id')->all());
        $this->assertSame($expectedNet, (int) $events->sum('amount'));
    }

    private function migration(string $file): Migration
    {
        $path = database_path('migrations/'.$file);
        $this->assertFileExists($path);
        $migration = require $path;
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    private function invokeMigration(Migration $migration, string $method): void
    {
        (new ReflectionMethod($migration, $method))->invoke($migration);
    }
}
