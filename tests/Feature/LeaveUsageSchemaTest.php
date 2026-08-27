<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\Cuti\LeaveUsageReconciliationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeaveUsageSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_rekonsiliasi_awal_menyimpan_tiga_tahun_dengan_cutoff_eksplisit(): void
    {
        [$employee, $actor] = $this->employeeAndActor();

        $set = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 2, 2026 => 0],
            Carbon::parse('2026-08-18'),
            'Rekonsiliasi awal.',
            $actor,
        );

        $records = $set->records()->orderBy('usage_year')->get();

        $this->assertSame('active', $set->status);
        $this->assertSame([2024, 2025, 2026], $records->pluck('usage_year')->all());
        $this->assertSame([0, 2, 0], $records->pluck('workdays')->all());
        $this->assertSame(
            ['2024-12-31', '2025-12-31', '2026-08-18'],
            $records->map(fn (LeaveUsageRecord $record): string => $record->effective_date->toDateString())->all(),
        );
        $this->assertTrue($records->every(
            fn (LeaveUsageRecord $record): bool => $record->start_date === null && $record->end_date === null,
        ));
        $this->assertTrue($records->every(
            fn (LeaveUsageRecord $record): bool => $record->jenisCuti->is($this->annualType()),
        ));
    }

    public function test_rekonsiliasi_menolak_key_tahun_yang_tidak_persis_n2_n1_n(): void
    {
        [$employee, $actor] = $this->employeeAndActor();

        $this->expectException(ValidationException::class);

        app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2026 => 0],
            Carbon::parse('2026-08-18'),
            'Tidak lengkap.',
            $actor,
        );
    }

    #[DataProvider('invalidRecordProvider')]
    public function test_database_menolak_kombinasi_fact_yang_tidak_valid(array $override): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $payload = array_merge($this->manualFactPayload($employee, $actor), $override);

        $this->expectException(QueryException::class);

        DB::table('leave_usage_records')->insert($payload);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidRecordProvider(): array
    {
        return [
            'source tidak dikenal' => [['source_type' => 'unknown']],
            'status tidak dikenal' => [['record_status' => 'deleted']],
            'manual nol hari' => [['workdays' => 0]],
            'manual tanpa awal' => [['start_date' => null]],
            'manual lintas tahun' => [['end_date' => '2027-01-02']],
            'tahun fakta berbeda dari periode' => [['usage_year' => 2025]],
            'rekonsiliasi tanpa set snapshot' => [[
                'source_type' => 'annual_reconciliation',
                'start_date' => null,
                'end_date' => null,
                'workdays' => 0,
            ]],
            'approved request tanpa request' => [['source_type' => 'approved_request']],
        ];
    }

    public function test_database_menolak_replacement_tanpa_alasan_koreksi(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $parent = $this->createManualFact($employee, $actor);
        $parent->forceFill([
            'record_status' => 'superseded',
            'correction_reason' => 'Versi diganti untuk pengujian.',
        ])->save();
        $replacement = array_merge($this->manualFactPayload($employee, $actor), [
            'id' => (string) Str::uuid(),
            'effective_date' => '2026-07-02',
            'start_date' => '2026-07-02',
            'end_date' => '2026-07-02',
            'replaces_id' => $parent->id,
            'correction_reason' => null,
        ]);

        $this->expectException(QueryException::class);
        DB::table('leave_usage_records')->insert($replacement);
    }

    public function test_database_menolak_duplikat_manual_aktif_yang_persis_sama(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $payload = $this->manualFactPayload($employee, $actor);
        DB::table('leave_usage_records')->insert($payload);
        $this->attachValidManualApprovalSnapshot(LeaveUsageRecord::query()->findOrFail($payload['id']));

        $payload['id'] = (string) Str::uuid();

        $this->expectException(QueryException::class);
        DB::table('leave_usage_records')->insert($payload);
    }

    public function test_database_menolak_fact_kedua_untuk_leave_request_yang_sama(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $requestId = $this->approvedLeaveRequest($employee)->id;
        $first = $this->approvedFactPayload($employee, $actor, $requestId);
        DB::table('leave_usage_records')->insert($first);

        $first['id'] = (string) Str::uuid();

        $this->expectException(QueryException::class);
        DB::table('leave_usage_records')->insert($first);
    }

    public function test_database_menolak_set_aktif_kedua_dan_lineage_set_bercabang(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $set = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 0],
            Carbon::parse('2026-08-18'),
            'Rekonsiliasi untuk guard set aktif.',
            $actor,
        );
        $base = [
            'employee_id' => $employee->id,
            'balance_year' => 2027,
            'reconciled_at' => '2027-01-02',
            'administrative_note' => 'Bypass database untuk pengujian constraint.',
            'recorded_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->assertInsertRejected('leave_usage_reconciliation_sets', array_merge($base, [
            'id' => (string) Str::uuid(),
            'status' => 'active',
        ]), 'leave_usage_reconciliation_one_active_per_employee');

        DB::table('leave_usage_reconciliation_sets')->insert(array_merge($base, [
            'id' => (string) Str::uuid(),
            'status' => 'superseded',
            'replaces_id' => $set->id,
            'correction_reason' => 'Versi historis pertama.',
        ]));
        $this->assertInsertRejected('leave_usage_reconciliation_sets', array_merge($base, [
            'id' => (string) Str::uuid(),
            'status' => 'superseded',
            'replaces_id' => $set->id,
            'correction_reason' => 'Cabang historis kedua.',
        ]), 'leave_usage_reconciliation_replaces_unique');
    }

    public function test_database_menolak_lineage_fact_bercabang(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $parent = $this->createManualFact($employee, $actor);
        $parent->forceFill([
            'record_status' => 'superseded',
            'correction_reason' => 'Disupersede untuk pengujian lineage.',
        ])->save();
        $firstChild = array_merge($this->manualFactPayload($employee, $actor), [
            'effective_date' => '2026-07-02',
            'start_date' => '2026-07-02',
            'end_date' => '2026-07-02',
            'replaces_id' => $parent->id,
            'correction_reason' => 'Versi pengganti pertama.',
        ]);
        DB::table('leave_usage_records')->insert($firstChild);
        $this->attachValidManualApprovalSnapshot(LeaveUsageRecord::query()->findOrFail($firstChild['id']));

        $this->assertInsertRejected('leave_usage_records', array_merge($firstChild, [
            'id' => (string) Str::uuid(),
            'effective_date' => '2026-07-03',
            'start_date' => '2026-07-03',
            'end_date' => '2026-07-03',
            'record_status' => 'superseded',
            'correction_reason' => 'Cabang pengganti kedua.',
        ]), 'leave_usage_replaces_unique');
    }

    public function test_uuid_dan_fk_restrict_fact_ditegakkan_postgresql(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $type = RefJenisCuti::create([
            'nama' => 'Cuti Khusus Constraint',
            'code' => 'constraint-test',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $payload = array_merge($this->manualFactPayload($employee, $actor), [
            'leave_type_id' => $type->id,
        ]);
        $record = $this->createManualFact($employee, $actor, ['leave_type_id' => $type->id]);

        $this->assertTrue(Str::isUuid($record->id));
        $this->assertInsertRejected('leave_usage_records', array_merge($payload, [
            'id' => 'bukan-uuid',
            'effective_date' => '2026-07-04',
            'start_date' => '2026-07-04',
            'end_date' => '2026-07-04',
        ]), 'invalid input syntax for type uuid');
        $this->assertStatementRejected(
            "DELETE FROM ref_jenis_cuti WHERE id = '{$type->id}'",
            'leave_usage_records_leave_type_id_foreign',
        );
    }

    public function test_model_fact_menolak_hard_delete(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $record = $this->createManualFact($employee, $actor);

        $this->expectException(LogicException::class);
        $record->delete();
    }

    public function test_postgresql_menolak_hard_delete_fact_meski_melewati_model(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $record = $this->createManualFact($employee, $actor);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('fakta pemakaian cuti bersifat historis');

        DB::table('leave_usage_records')->where('id', $record->id)->delete();
    }

    public function test_postgresql_menolak_truncate_fact_dan_delete_truncate_set(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $set = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 0],
            Carbon::parse('2026-08-18'),
            'Rekonsiliasi untuk guard.',
            $actor,
        );

        foreach ([
            'TRUNCATE TABLE leave_usage_records CASCADE',
            "DELETE FROM leave_usage_reconciliation_sets WHERE id = '{$set->id}'",
            'TRUNCATE TABLE leave_usage_reconciliation_sets CASCADE',
        ] as $statement) {
            $this->assertStatementRejected($statement, 'bersifat historis');
        }
    }

    public function test_postgresql_menolak_perubahan_kolom_substantif_fact(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $record = $this->createManualFact($employee, $actor);
        $otherEmployee = Employee::factory()->create();

        foreach ([
            "employee_id = '{$otherEmployee->id}'",
            'workdays = 2',
            "effective_date = '2026-07-02'",
            "administrative_note = 'Isi fakta diubah tanpa versi pengganti.'",
        ] as $assignment) {
            $this->assertStatementRejected(
                "UPDATE leave_usage_records SET {$assignment} WHERE id = '{$record->id}'",
                'kolom substantif bersifat immutable',
            );
        }
    }

    public function test_postgresql_menolak_perubahan_kolom_substantif_set_rekonsiliasi(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $set = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 0],
            Carbon::parse('2026-08-18'),
            'Catatan tahunan untuk pengujian immutability.',
            $actor,
        );
        $otherEmployee = Employee::factory()->create();
        $otherActor = User::factory()->adminKepegawaian()->create();

        foreach ([
            "employee_id = '{$otherEmployee->id}'",
            'balance_year = 2027',
            "reconciled_at = '2026-08-19'",
            "administrative_note = 'Catatan historis diubah langsung.'",
            "recorded_by = '{$otherActor->id}'",
            "created_at = created_at + INTERVAL '1 second'",
        ] as $assignment) {
            $this->assertStatementRejected(
                "UPDATE leave_usage_reconciliation_sets SET {$assignment} WHERE id = '{$set->id}'",
                'kolom substantif bersifat immutable',
            );
        }
    }

    public function test_postgresql_hanya_mengizinkan_transisi_lifecycle_set_aktif(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $set = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 0],
            Carbon::parse('2026-08-18'),
            'Catatan tahunan untuk pengujian lifecycle.',
            $actor,
        );

        DB::table('leave_usage_reconciliation_sets')->where('id', $set->id)->update([
            'status' => LeaveUsageReconciliationSet::STATUS_SUPERSEDED,
            'updated_at' => now()->addSecond(),
        ]);

        $this->assertSame(LeaveUsageReconciliationSet::STATUS_SUPERSEDED, $set->fresh()->status);
        $this->assertStatementRejected(
            "UPDATE leave_usage_reconciliation_sets SET status = 'active' WHERE id = '{$set->id}'",
            'lifecycle terminal bersifat immutable',
        );
    }

    public function test_postgresql_hanya_mengizinkan_transisi_lifecycle_fact_aktif(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $record = $this->createManualFact($employee, $actor);

        DB::table('leave_usage_records')->where('id', $record->id)->update([
            'record_status' => LeaveUsageRecord::STATUS_SUPERSEDED,
            'correction_reason' => 'Fakta diganti melalui lifecycle resmi.',
            'updated_at' => now()->addSecond(),
        ]);

        $this->assertSame(LeaveUsageRecord::STATUS_SUPERSEDED, $record->fresh()->record_status);
        $this->assertStatementRejected(
            "UPDATE leave_usage_records SET correction_reason = 'Alasan terminal ditulis ulang.' WHERE id = '{$record->id}'",
            'lifecycle terminal bersifat immutable',
        );
    }

    public function test_membership_wajib_mengikat_set_deklarasi_dan_fact_itemized_pegawai_tahun_yang_sama(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $otherEmployee = Employee::factory()->create();
        $set = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 0],
            Carbon::parse('2026-08-18'),
            'Rekonsiliasi untuk membership.',
            $actor,
        );
        $annual = $set->records()->where('usage_year', 2026)->firstOrFail();
        $foreignFact = $this->createManualFact($otherEmployee, $actor);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('membership rekonsiliasi tidak konsisten');

        DB::table('leave_usage_reconciliation_memberships')->insert([
            'id' => (string) Str::uuid(),
            'reconciliation_set_id' => $set->id,
            'annual_reconciliation_record_id' => $annual->id,
            'itemized_usage_record_id' => $foreignFact->id,
            'included_workdays' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_membership_yang_sudah_dibekukan_menolak_update(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $fact = $this->createManualFact($employee, $actor);
        app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 1],
            Carbon::parse('2026-08-18'),
            'Rekonsiliasi immutable membership.',
            $actor,
        );
        $membershipId = DB::table('leave_usage_reconciliation_memberships')
            ->where('itemized_usage_record_id', $fact->id)
            ->value('id');

        $this->assertStatementRejected(
            "UPDATE leave_usage_reconciliation_memberships SET included_workdays = 2 WHERE id = '{$membershipId}'",
            'bersifat immutable',
        );
    }

    public function test_membership_menolak_tahun_source_dan_status_itemized_yang_tidak_sesuai(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $set = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 0],
            Carbon::parse('2026-08-18'),
            'Rekonsiliasi validasi membership lengkap.',
            $actor,
        );
        $annual = $set->records()->where('usage_year', 2026)->firstOrFail();
        $wrongYear = $this->createManualFact($employee, $actor, [
            'usage_year' => 2025,
            'effective_date' => '2025-07-01',
            'start_date' => '2025-07-01',
            'end_date' => '2025-07-01',
        ]);
        $inactive = $this->createManualFact($employee, $actor, [
            'effective_date' => '2026-07-02',
            'start_date' => '2026-07-02',
            'end_date' => '2026-07-02',
        ]);
        $inactive->forceFill([
            'record_status' => 'cancelled',
            'correction_reason' => 'Dibatalkan untuk fixture validasi membership.',
        ])->save();

        foreach ([$wrongYear, $inactive, $annual] as $itemized) {
            $this->assertMembershipRejected([
                'id' => (string) Str::uuid(),
                'reconciliation_set_id' => $set->id,
                'annual_reconciliation_record_id' => $annual->id,
                'itemized_usage_record_id' => $itemized->id,
                'included_workdays' => max(1, $itemized->workdays),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_rekonsiliasi_agregat_menolak_fk_request_dan_case_yang_tidak_relevan(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $set = app(LeaveUsageReconciliationService::class)->createAnnualReconciliationSet(
            $employee,
            2026,
            [2024 => 0, 2025 => 0, 2026 => 0],
            Carbon::parse('2026-08-18'),
            'Rekonsiliasi validasi source contract.',
            $actor,
        );
        $annual = $set->records()->where('usage_year', 2026)->firstOrFail();
        $request = $this->approvedLeaveRequest($employee);
        $caseId = (string) Str::uuid();
        DB::table('leave_request_cases')->insert([
            'id' => $caseId,
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $this->annualType()->id,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['leave_request_id' => $request->id, 'leave_request_case_id' => $caseId] as $column => $value) {
            $payload = array_merge($annual->getAttributes(), [
                'id' => (string) Str::uuid(),
                'usage_year' => 2027,
                'effective_date' => '2027-12-31',
                $column => $value,
            ]);

            $this->assertInsertRejected(
                'leave_usage_records',
                $payload,
                'leave_usage_source_contract_check',
            );
        }
    }

    public function test_postgresql_menolak_update_delete_dan_truncate_ledger(): void
    {
        [$employee, $actor] = $this->employeeAndActor();
        $ledger = LeaveBalanceLedger::create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED,
            'amount' => 1,
            'reason' => 'Pengujian append-only.',
            'dedup_key' => 'schema-ledger-append-only',
            'created_by' => $actor->id,
            'occurred_at' => now(),
        ]);

        foreach ([
            "UPDATE leave_balance_ledger SET amount = 2 WHERE id = '{$ledger->id}'",
            "DELETE FROM leave_balance_ledger WHERE id = '{$ledger->id}'",
            'TRUNCATE TABLE leave_balance_ledger',
        ] as $statement) {
            $this->assertStatementRejected($statement, 'append-only');
        }
    }

    private function assertStatementRejected(string $statement, string $message): void
    {
        try {
            DB::transaction(fn (): bool => DB::statement($statement));
            $this->fail("Statement seharusnya ditolak: {$statement}");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertMembershipRejected(array $payload): void
    {
        try {
            DB::transaction(fn (): bool => DB::table('leave_usage_reconciliation_memberships')->insert($payload));
            $this->fail('Membership yang tidak konsisten seharusnya ditolak.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('membership rekonsiliasi tidak konsisten', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertInsertRejected(string $table, array $payload, string $message): void
    {
        try {
            DB::transaction(fn (): bool => DB::table($table)->insert($payload));
            $this->fail("Insert ke {$table} seharusnya ditolak.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /** @return array{Employee, User} */
    private function employeeAndActor(): array
    {
        $this->annualType();
        $employee = Employee::factory()->create();
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);

        return [
            $employee,
            User::factory()->adminKepegawaian()->create(),
        ];
    }

    /** @return array<string, mixed> */
    private function manualFactPayload(Employee $employee, User $actor): array
    {
        return [
            'id' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => $this->annualType()->id,
            'source_type' => 'manual_external',
            'reconciliation_set_id' => null,
            'leave_request_id' => null,
            'leave_request_case_id' => null,
            'usage_year' => 2026,
            'effective_date' => '2026-07-01',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-01',
            'workdays' => 1,
            'administrative_note' => 'Cuti final di luar SIMPEG.',
            'record_status' => 'active',
            'replaces_id' => null,
            'correction_reason' => null,
            'recorded_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function createManualFact(Employee $employee, User $actor, array $overrides = []): LeaveUsageRecord
    {
        $record = LeaveUsageRecord::query()->create(array_merge(
            $this->manualFactPayload($employee, $actor),
            $overrides,
        ));

        return $this->attachValidManualApprovalSnapshot($record);
    }

    /** @return array<string, mixed> */
    private function approvedFactPayload(Employee $employee, User $actor, string $requestId): array
    {
        return array_merge($this->manualFactPayload($employee, $actor), [
            'source_type' => 'approved_request',
            'leave_request_id' => $requestId,
        ]);
    }

    private function annualType(): RefJenisCuti
    {
        return RefJenisCuti::firstOrCreate(
            ['code' => 'tahunan'],
            ['nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false],
        );
    }

    private function approvedLeaveRequest(Employee $employee): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $this->annualType()->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => '2026-07-01',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Cuti final.',
            'status' => 'disetujui',
        ]);
    }
}
