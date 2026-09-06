<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeaveUsageSchemaTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_postgresql_menolak_truncate_fact(): void
    {
        [$employee, $actor] = $this->employeeAndActor();

        $this->createManualFact($employee, $actor);

        // Dalam test transaction PostgreSQL dapat menghentikan TRUNCATE lebih awal
        // karena pending trigger event; kedua pesan sama-sama membuktikan operasi
        // destruktif tidak dapat dijalankan.
        $this->assertStatementRejected('TRUNCATE TABLE leave_usage_records CASCADE', 'cannot TRUNCATE');
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
