<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveApprovalChainStep;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestCase;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Models\SimpegNotification;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Memastikan fondasi penyimpanan revisi cuti tersedia sebelum alur approval dinamis dipakai.
 */
class CutiFoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_duty_postponement_domain_tokens_are_dedicated(): void
    {
        $this->assertSame('ditangguhkan_tugas_dinas', LeaveRequest::STATUS_DUTY_POSTPONED);
        $this->assertSame('ditangguhkan_tugas_dinas', LeaveRequestStep::STATUS_DUTY_POSTPONED);
        $this->assertSame('duty_postponement_terminal', LeaveRequestStep::SKIPPED_DUTY_POSTPONEMENT_TERMINAL);
        $this->assertSame('DUTY_POSTPONEMENT', LeaveApproval::ACTION_DUTY_POSTPONEMENT);
    }

    public function test_rollover_return_schema_accepts_supported_statuses_and_enforces_target_year_contract_on_postgresql(): void
    {
        $this->assertTrue(Schema::hasColumns('leave_requests', [
            'rollover_source_year',
            'rollover_target_year',
        ]));

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint rollover return diverifikasi khusus pada PostgreSQL.');
        }

        $employee = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Kontrak Status Rollover',
            'code' => 'kontrak_status_rollover',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);

        foreach ([
            'menunggu_approval',
            'ditangguhkan',
            'ditangguhkan_tugas_dinas',
            'perlu_perubahan',
            'disetujui',
            'tidak_disetujui',
            'dikembalikan_karena_rollover',
        ] as $status) {
            $request = LeaveRequest::create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $jenisCuti->id,
                'tanggal_mulai' => '2026-12-20',
                'tanggal_selesai' => '2026-12-20',
                'jumlah_hari_kerja' => 1,
                'alasan' => "Fixture status {$status}.",
                'status' => $status,
                'rollover_source_year' => 2026,
                'rollover_target_year' => 2027,
            ]);

            $this->assertSame($status, $request->status);
        }

        $this->assertThrows(
            fn () => LeaveRequest::create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $jenisCuti->id,
                'tanggal_mulai' => '2026-12-21',
                'tanggal_selesai' => '2026-12-21',
                'jumlah_hari_kerja' => 1,
                'alasan' => 'Tahun target tidak valid.',
                'status' => 'dikembalikan_karena_rollover',
                'rollover_source_year' => 2026,
                'rollover_target_year' => 2028,
            ]),
            QueryException::class,
        );
        $this->assertThrows(
            fn () => LeaveRequest::create([
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $jenisCuti->id,
                'tanggal_mulai' => '2026-12-22',
                'tanggal_selesai' => '2026-12-22',
                'jumlah_hari_kerja' => 1,
                'alasan' => 'Status acak tidak boleh lolos constraint.',
                'status' => 'status_sembarang',
            ]),
            QueryException::class,
        );
    }

    public function test_rollover_return_migration_normalizes_known_original_enum_statuses_before_check_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Normalisasi status legacy rollover diverifikasi khusus pada PostgreSQL.');
        }

        $migration = $this->rolloverReturnMigration();
        $employee = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Status Legacy Rollover',
            'code' => 'status_legacy_rollover',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $this->invokeMigrationMethod($migration, 'down');

        try {
            $expectedStatuses = [
                'Draft' => 'menunggu_approval',
                'Disetujui' => 'disetujui',
                'Tidak Disetujui' => 'tidak_disetujui',
            ];

            foreach ($expectedStatuses as $legacyStatus => $canonicalStatus) {
                DB::table('leave_requests')->insert([
                    'id' => (string) Str::uuid(),
                    'employee_id' => $employee->id,
                    'jenis_cuti_id' => $jenisCuti->id,
                    'tanggal_mulai' => '2026-12-20',
                    'tanggal_selesai' => '2026-12-20',
                    'jumlah_hari_kerja' => 1,
                    'alasan' => "Fixture {$legacyStatus}.",
                    'status' => $legacyStatus,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->invokeMigrationMethod($migration, 'up');

            foreach ($expectedStatuses as $legacyStatus => $canonicalStatus) {
                $this->assertDatabaseHas('leave_requests', [
                    'alasan' => "Fixture {$legacyStatus}.",
                    'status' => $canonicalStatus,
                ]);
            }
        } finally {
            if (! Schema::hasColumn('leave_requests', 'rollover_source_year')) {
                $this->invokeMigrationMethod($migration, 'up');
            }
        }
    }

    public function test_rollover_return_migration_rejects_unknown_legacy_status_before_adding_check_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Penolakan token legacy tak dikenal diverifikasi khusus pada PostgreSQL.');
        }

        $migration = $this->rolloverReturnMigration();
        $employee = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Status Legacy Tidak Dikenal',
            'code' => 'status_legacy_tidak_dikenal',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $this->invokeMigrationMethod($migration, 'down');

        try {
            DB::table('leave_requests')->insert([
                'id' => (string) Str::uuid(),
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $jenisCuti->id,
                'tanggal_mulai' => '2026-12-20',
                'tanggal_selesai' => '2026-12-20',
                'jumlah_hari_kerja' => 1,
                'alasan' => 'Fixture token status legacy tidak dikenal.',
                'status' => 'token_legacy_tidak_dikenal',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('token_legacy_tidak_dikenal');
            $this->invokeMigrationMethod($migration, 'up');
        } finally {
            DB::table('leave_requests')->where('status', 'token_legacy_tidak_dikenal')->delete();

            if (Schema::hasColumn('leave_requests', 'rollover_source_year')) {
                $this->invokeMigrationMethod($migration, 'down');
            }

            $this->invokeMigrationMethod($migration, 'up');
        }
    }

    #[DataProvider('rolloverReturnEvidenceProvider')]
    public function test_rollover_return_migration_down_refuses_when_return_evidence_exists_postgresql(string $evidence): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Proteksi rollback rollover diverifikasi khusus pada PostgreSQL.');
        }

        $employee = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Bukti Rollover',
            'code' => 'bukti_rollover',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-12-20',
            'tanggal_selesai' => '2026-12-20',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Bukti pengembalian rollover tidak boleh kehilangan metadata.',
            'status' => $evidence === 'request' ? LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER : 'menunggu_approval',
            'rollover_source_year' => $evidence === 'request' ? 2026 : null,
            'rollover_target_year' => $evidence === 'request' ? 2027 : null,
        ]);

        if ($evidence === 'release_event') {
            $balance = LeaveBalance::create([
                'employee_id' => $employee->id,
                'tahun' => 2026,
                'jatah_awal' => 12,
                'carry_over' => 0,
                'terpakai' => 0,
                'sisa' => 12,
            ]);
            LeaveBalanceReservationEvent::create([
                'employee_id' => $employee->id,
                'leave_request_id' => $request->id,
                'leave_balance_id' => $balance->id,
                'tahun' => 2026,
                'event_type' => LeaveBalanceReservationEvent::EVENT_RELEASED,
                'amount' => -1,
                'dedup_key' => "rollover-release:{$request->id}",
                'metadata' => ['release_context' => 'rollover_return'],
            ]);
        }

        if ($evidence === 'notification') {
            SimpegNotification::create([
                'user_id' => $employee->id,
                'type' => 'cuti.dikembalikan_karena_rollover',
                'title' => 'Pengajuan Cuti Dikembalikan karena Rollover',
                'body' => 'Notifikasi rollover untuk proteksi rollback.',
            ]);
        }

        if ($evidence === 'audit') {
            AuditLog::create([
                'event' => 'UPDATE',
                'auditable_type' => LeaveRequest::class,
                'auditable_id' => $request->id,
                'new_values' => ['status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER],
            ]);
        }

        if ($evidence === 'release_audit') {
            AuditLog::create([
                'event' => 'LEAVE_BALANCE_RESERVATION_RELEASED',
                'auditable_type' => 'LeaveBalanceReservationEvent',
                'auditable_id' => (string) Str::uuid(),
                'new_values' => ['release_context' => 'rollover_return'],
            ]);
        }

        $this->assertThrows(
            fn () => $this->invokeMigrationMethod($this->rolloverReturnMigration(), 'down'),
            RuntimeException::class,
        );
        $this->assertTrue(Schema::hasColumns('leave_requests', [
            'rollover_source_year',
            'rollover_target_year',
        ]));
    }

    /** @return array<string, array{string}> */
    public static function rolloverReturnEvidenceProvider(): array
    {
        return [
            'status dan metadata request' => ['request'],
            'event pelepasan reservasi rollover' => ['release_event'],
            'notifikasi rollover' => ['notification'],
            'audit pengembalian rollover' => ['audit'],
            'audit pelepasan reservasi rollover' => ['release_audit'],
        ];
    }

    public function test_duty_postponement_audit_event_is_allowed_and_random_event_is_rejected_by_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint event audit diverifikasi khusus pada PostgreSQL.');
        }

        AuditLog::create([
            'event' => 'DUTY_POSTPONEMENT',
            'auditable_type' => LeaveRequest::class,
            'auditable_id' => (string) Str::uuid(),
        ]);

        $this->assertDatabaseHas('audit_logs', ['event' => 'DUTY_POSTPONEMENT']);
        $this->assertThrows(
            fn () => DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'event' => 'RANDOM_DUTY_POSTPONEMENT_EVENT',
                'auditable_type' => LeaveRequest::class,
                'created_at' => now(),
            ]),
            QueryException::class,
        );
    }

    public function test_duty_postponement_migration_refuses_rollback_when_audit_evidence_exists(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Rollback constraint audit diverifikasi khusus pada PostgreSQL.');
        }

        AuditLog::create([
            'event' => 'DUTY_POSTPONEMENT',
            'auditable_type' => LeaveRequest::class,
            'auditable_id' => (string) Str::uuid(),
        ]);

        $this->assertThrows(
            fn () => $this->invokeMigrationMethod($this->dutyPostponementMigration(), 'down'),
            RuntimeException::class,
        );
        $this->assertDutyPostponementMigrationSupportRemainsIntact();
    }

    public function test_duty_postponement_migration_refuses_rollback_when_notification_evidence_exists(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Rollback notifikasi penangguhan diverifikasi khusus pada PostgreSQL.');
        }

        $employee = Employee::factory()->create();
        SimpegNotification::create([
            'user_id' => $employee->id,
            'type' => 'cuti.ditangguhkan_tugas_dinas',
            'title' => 'Uji penangguhan tugas dinas',
            'body' => 'Notifikasi tanpa data pribadi untuk menguji proteksi rollback.',
        ]);

        $this->assertThrows(
            fn () => $this->invokeMigrationMethod($this->dutyPostponementMigration(), 'down'),
            RuntimeException::class,
        );
        $this->assertDutyPostponementMigrationSupportRemainsIntact();
    }

    public function test_duty_postponement_migration_refuses_rollback_when_ledger_evidence_exists(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Rollback ledger penangguhan diverifikasi khusus pada PostgreSQL.');
        }

        $employee = Employee::factory()->create();
        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED,
            'amount' => 0,
        ]);

        $this->assertThrows(
            fn () => $this->invokeMigrationMethod($this->dutyPostponementMigration(), 'down'),
            RuntimeException::class,
        );
        $this->assertDutyPostponementMigrationSupportRemainsIntact();
    }

    public function test_duty_postponement_migration_clean_rollback_removes_policies_and_restores_prior_audit_allowlist(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Rollback bersih constraint audit diverifikasi khusus pada PostgreSQL.');
        }

        $migration = $this->dutyPostponementMigration();

        try {
            $this->invokeMigrationMethod($migration, 'down');

            $this->assertSame(0, DB::table('notification_event_channels')
                ->where('event_key', 'cuti.ditangguhkan_tugas_dinas')
                ->count());

            AuditLog::create([
                'event' => 'POSTPONE',
                'auditable_type' => LeaveRequest::class,
                'auditable_id' => (string) Str::uuid(),
            ]);
            $this->assertDatabaseHas('audit_logs', ['event' => 'POSTPONE']);

            $this->assertThrows(
                fn () => DB::transaction(fn () => AuditLog::create([
                    'event' => 'DUTY_POSTPONEMENT',
                    'auditable_type' => LeaveRequest::class,
                    'auditable_id' => (string) Str::uuid(),
                ])),
                QueryException::class,
            );
        } finally {
            $this->invokeMigrationMethod($migration, 'up');
        }

        $this->assertDutyPostponementMigrationSupportRemainsIntact();
    }

    public function test_schema_fondasi_revisi_cuti_tersedia(): void
    {
        $expectedColumns = [
            'leave_approval_chains' => [
                'id',
                'employee_id',
                'name',
                'is_active',
                'effective_from',
                'effective_until',
                'created_by',
                'updated_by',
                'change_reason',
            ],
            'leave_approval_chain_steps' => [
                'id',
                'leave_approval_chain_id',
                'step_order',
                'step_type',
                'role_label',
                'approver_role_key',
                'approver_employee_id',
                'is_final',
            ],
            'leave_pybmc_global_config' => [
                'id',
                'approver_employee_id',
                'effective_from',
                'created_by',
                'change_reason',
            ],
            'leave_request_steps' => [
                'id',
                'leave_request_id',
                'step_order',
                'step_type',
                'role_label',
                'approver_employee_id',
                'status',
                'is_final',
                'skipped_reason',
                'acted_at',
                'decision_note',
            ],
            'leave_requests' => [
                'leave_request_case_id',
            ],
            'leave_request_cases' => [
                'id',
                'employee_id',
                'jenis_cuti_id',
                'created_by',
            ],
            'leave_balances' => [
                'sisa_n2',
                'sisa_n1',
                'sisa_tahun_berjalan',
                'terpakai_tahun_berjalan',
                'hangus',
            ],
            'leave_balance_ledger' => [
                'id',
                'employee_id',
                'leave_request_id',
                'leave_balance_id',
                'tahun',
                'event_type',
                'amount',
                'source_year',
                'sumber_carry_over',
                'reason',
                'dedup_key',
                'metadata',
                'created_by',
                'occurred_at',
            ],
            'leave_balance_reservation_events' => [
                'id',
                'employee_id',
                'leave_request_id',
                'leave_balance_id',
                'tahun',
                'event_type',
                'amount',
                'reason',
                'dedup_key',
                'metadata',
                'created_by',
                'occurred_at',
            ],
            'leave_proofs' => [
                'id',
                'leave_request_id',
                'token',
                'document_path',
                'document_mime',
                'generated_by',
                'generated_at',
                'metadata',
            ],
        ];

        foreach ($expectedColumns as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Tabel {$table} wajib tersedia.");
            $this->assertTrue(Schema::hasColumns($table, $columns), "Kolom fondasi {$table} belum lengkap.");
        }
    }

    public function test_model_fondasi_revisi_cuti_memiliki_relasi_dasar(): void
    {
        $pemohon = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Uji',
            'code' => 'tahunan_uji',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $cuti = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji fondasi revisi cuti.',
            'status' => 'menunggu_approval',
        ]);
        $leaveCase = LeaveRequestCase::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
        ]);
        $cuti->update(['leave_request_case_id' => $leaveCase->id]);
        $saldo = LeaveBalance::create([
            'employee_id' => $pemohon->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);
        $chain = LeaveApprovalChain::create([
            'employee_id' => $pemohon->id,
            'name' => 'Rantai utama',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Uji fondasi.',
        ]);

        LeaveApprovalChainStep::create([
            'leave_approval_chain_id' => $chain->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Atasan Langsung',
            'approver_employee_id' => $approver->id,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $cuti->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Atasan Langsung',
            'approver_employee_id' => $approver->id,
            'status' => 'pending',
        ]);
        LeaveBalanceLedger::create([
            'employee_id' => $pemohon->id,
            'leave_balance_id' => $saldo->id,
            'tahun' => 2026,
            'event_type' => 'opening_balance_set',
            'amount' => 12,
        ]);
        $reservation = LeaveBalanceReservationEvent::create([
            'employee_id' => $pemohon->id,
            'leave_request_id' => $cuti->id,
            'leave_balance_id' => $saldo->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 1,
            'dedup_key' => 'reservation-uji-fondasi',
        ]);
        $proof = LeaveProof::create([
            'leave_request_id' => $cuti->id,
            'token' => 'token-uji-fondasi',
            'document_mime' => 'application/pdf',
            'metadata' => [
                'snapshot' => [
                    'nomor_pengajuan' => 'CUTI-2026-0001',
                ],
            ],
        ]);

        $this->assertCount(1, $chain->steps);
        $this->assertCount(1, $cuti->steps);
        $this->assertSame($leaveCase->id, $cuti->leaveRequestCase->id);
        $this->assertSame($pemohon->id, $leaveCase->employee->id);
        $this->assertSame($jenisCuti->id, $leaveCase->jenisCuti->id);
        $this->assertSame($cuti->id, $leaveCase->leaveRequests->sole()->id);
        $this->assertTrue($saldo->ledgerEntries()->where('event_type', 'opening_balance_set')->exists());
        $this->assertSame(1, $cuti->balanceReservationEvents()->sum('amount'));
        $this->assertSame($reservation->id, $saldo->reservationEvents()->firstOrFail()->id);
        $this->assertSame('token-uji-fondasi', $cuti->proof->token);
        $this->assertSame('application/pdf', $proof->document_mime);
        $this->assertSame([
            'snapshot' => [
                'nomor_pengajuan' => 'CUTI-2026-0001',
            ],
        ], $proof->fresh()->metadata);
    }

    public function test_constraint_fondasi_revisi_cuti_menolak_duplikasi_kritis(): void
    {
        $pemohon = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Constraint',
            'code' => 'tahunan_constraint',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $cuti = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji constraint fondasi revisi cuti.',
            'status' => 'menunggu_approval',
        ]);
        $chain = LeaveApprovalChain::create([
            'employee_id' => $pemohon->id,
            'name' => 'Rantai aktif',
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(QueryException::class);
        LeaveApprovalChain::create([
            'employee_id' => $pemohon->id,
            'name' => 'Rantai aktif duplikat',
            'effective_from' => '2026-01-02',
        ]);
    }

    public function test_rangkaian_pengajuan_cuti_bersifat_append_only(): void
    {
        $pemohon = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Melahirkan Append Only',
            'code' => 'melahirkan_append_only',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leaveCase = LeaveRequestCase::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
        ]);

        $this->expectException(LogicException::class);
        $leaveCase->update(['created_by' => Str::uuid()->toString()]);
    }

    public function test_constraint_fondasi_revisi_cuti_menolak_duplikasi_token_bukti(): void
    {
        $pemohon = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Token',
            'code' => 'tahunan_token',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $cuti = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji token proof.',
            'status' => 'menunggu_approval',
        ]);
        $cutiLain = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-07',
            'tanggal_selesai' => '2026-07-07',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji token proof lain.',
            'status' => 'menunggu_approval',
        ]);

        LeaveProof::create(['leave_request_id' => $cuti->id, 'token' => 'token-duplikat']);
        $this->assertThrows(
            fn () => LeaveProof::create(['leave_request_id' => $cutiLain->id, 'token' => 'token-duplikat']),
            QueryException::class,
        );
    }

    public function test_constraint_fondasi_revisi_cuti_menolak_duplikasi_dedup_key_ledger(): void
    {
        $pemohon = Employee::factory()->create();
        $saldo = LeaveBalance::create([
            'employee_id' => $pemohon->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);

        LeaveBalanceLedger::create([
            'employee_id' => $pemohon->id,
            'leave_balance_id' => $saldo->id,
            'tahun' => 2026,
            'event_type' => 'opening_balance_set',
            'amount' => 12,
            'dedup_key' => 'saldo-awal-2026',
        ]);
        $this->assertThrows(
            fn () => LeaveBalanceLedger::create([
                'employee_id' => $pemohon->id,
                'leave_balance_id' => $saldo->id,
                'tahun' => 2026,
                'event_type' => 'opening_balance_set',
                'amount' => 12,
                'dedup_key' => 'saldo-awal-2026',
            ]),
            QueryException::class,
        );
    }

    public function test_ledger_menerima_seluruh_event_type_resmi(): void
    {
        $employee = Employee::factory()->create();

        foreach (LeaveBalanceLedger::eventTypes() as $index => $eventType) {
            LeaveBalanceLedger::create([
                'employee_id' => $employee->id,
                'tahun' => 2026,
                'event_type' => $eventType,
                'amount' => 0,
                'dedup_key' => "event-resmi-{$index}",
            ]);
        }

        $this->assertSame(
            LeaveBalanceLedger::eventTypes(),
            LeaveBalanceLedger::query()->orderBy('event_type')->pluck('event_type')->all(),
        );
    }

    public function test_ledger_menolak_event_type_tidak_dikenal(): void
    {
        $employee = Employee::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('event_type ledger cuti tidak diizinkan: event_sembarang');

        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'event_type' => 'event_sembarang',
            'amount' => 0,
        ]);
    }

    public function test_semua_event_ledger_tidak_dapat_diubah_atau_dihapus(): void
    {
        $employee = Employee::factory()->create();
        $actorId = User::factory()->create()->id;

        foreach (LeaveBalanceLedger::eventTypes() as $index => $eventType) {
            $ledger = LeaveBalanceLedger::create([
                'employee_id' => $employee->id,
                'tahun' => 2026,
                'event_type' => $eventType,
                'amount' => $index,
                'source_year' => 2026,
                'reason' => "Event immutable {$eventType}.",
                'dedup_key' => "immutable:{$eventType}:{$index}",
                'metadata' => ['sequence' => $index],
                'created_by' => $actorId,
                'occurred_at' => now()->subHour(),
            ]);
            $persisted = $this->openingLedgerPayload($ledger);

            try {
                $ledger->forceFill([
                    'event_type' => LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET,
                    'amount' => 99,
                    'reason' => 'Percobaan ubah ledger.',
                ])->save();
                $this->fail("Update {$eventType} harus ditolak.");
            } catch (LogicException $exception) {
                $this->assertSame('Ledger saldo cuti bersifat append-only dan tidak dapat diubah.', $exception->getMessage());
            }

            $this->assertSame($persisted, $this->openingLedgerPayload($ledger->fresh()));

            try {
                $ledger->fresh()->delete();
                $this->fail("Delete {$eventType} harus ditolak.");
            } catch (LogicException $exception) {
                $this->assertSame('Ledger saldo cuti bersifat append-only dan tidak dapat dihapus.', $exception->getMessage());
            }

            $this->assertSame($persisted, $this->openingLedgerPayload(LeaveBalanceLedger::findOrFail($ledger->id)));
        }
    }

    public function test_reservation_event_menolak_mutasi_dan_event_type_tidak_dikenal(): void
    {
        $employee = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Reservasi',
            'code' => 'tahunan_reservasi',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $cuti = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji event reservasi.',
            'status' => 'menunggu_approval',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('event_type reservasi saldo cuti tidak diizinkan: event_sembarang');

        LeaveBalanceReservationEvent::create([
            'employee_id' => $employee->id,
            'leave_request_id' => $cuti->id,
            'tahun' => 2026,
            'event_type' => 'event_sembarang',
            'amount' => 1,
        ]);
    }

    public function test_reservation_event_append_only_after_written(): void
    {
        $employee = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Reservasi Immutable',
            'code' => 'tahunan_reservasi_immutable',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $cuti = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji immutability reservasi.',
            'status' => 'menunggu_approval',
        ]);
        $event = LeaveBalanceReservationEvent::create([
            'employee_id' => $employee->id,
            'leave_request_id' => $cuti->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 1,
        ]);

        $this->expectException(LogicException::class);
        $event->forceFill(['amount' => 2])->save();
    }

    #[DataProvider('forbiddenCashConversionEventProvider')]
    public function test_ledger_menolak_event_konversi_saldo(string $eventType): void
    {
        $employee = Employee::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'event_type' => $eventType,
            'amount' => 0,
        ]);
    }

    /** @return array<string, array{string}> */
    public static function forbiddenCashConversionEventProvider(): array
    {
        return [
            'cash conversion' => ['cash_conversion'],
            'money payout' => ['money_payout'],
            'leave compensation' => ['leave_compensation'],
            'saldo diuangkan' => ['saldo_diuangkan'],
        ];
    }

    public function test_postgresql_constraint_menolak_event_ledger_di_luar_allowlist(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint event ledger diverifikasi khusus pada PostgreSQL.');
        }

        $employee = Employee::factory()->create();

        $this->assertThrows(
            fn () => DB::table('leave_balance_ledger')->insert([
                'id' => (string) Str::uuid(),
                'employee_id' => $employee->id,
                'tahun' => 2026,
                'event_type' => 'cash_conversion',
                'amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            QueryException::class,
        );
    }

    /** @return array<string, mixed> */
    private function openingLedgerPayload(LeaveBalanceLedger $ledger): array
    {
        return [
            'event_type' => $ledger->event_type,
            'amount' => $ledger->amount,
            'reason' => $ledger->reason,
            'metadata' => $ledger->metadata,
            'created_by' => $ledger->created_by,
            'occurred_at' => $ledger->occurred_at?->toIso8601String(),
            'created_at' => $ledger->created_at?->toIso8601String(),
            'updated_at' => $ledger->updated_at?->toIso8601String(),
        ];
    }

    private function dutyPostponementMigration(): Migration
    {
        return require database_path('migrations/2026_07_31_000001_add_duty_postponement_workflow_support.php');
    }

    private function rolloverReturnMigration(): Migration
    {
        return require database_path('migrations/2026_08_04_000001_add_rollover_return_support_to_leave_requests.php');
    }

    private function invokeMigrationMethod(Migration $migration, string $method): void
    {
        $callback = [$migration, $method];
        $this->assertIsCallable($callback);
        call_user_func($callback);
    }

    private function assertDutyPostponementMigrationSupportRemainsIntact(): void
    {
        $enabledChannels = DB::table('notification_event_channels')
            ->join('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->where('notification_event_channels.event_key', 'cuti.ditangguhkan_tugas_dinas')
            ->where('notification_event_channels.is_enabled', true)
            ->orderBy('ref_notification_channels.code')
            ->pluck('ref_notification_channels.code')
            ->all();
        $constraintDefinition = DB::table('pg_constraint')
            ->where('conname', 'audit_logs_event_check')
            ->value(DB::raw('pg_get_constraintdef(oid)'));

        $this->assertSame(['email', 'in_app'], $enabledChannels);
        $this->assertIsString($constraintDefinition);
        $this->assertStringContainsString('DUTY_POSTPONEMENT', $constraintDefinition);
    }
}
