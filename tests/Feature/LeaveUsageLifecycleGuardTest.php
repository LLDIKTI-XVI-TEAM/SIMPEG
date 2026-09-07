<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\Cuti\LeaveUsageRecordService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Regresi PostgreSQL untuk lifecycle fakta pemakaian dan lineage koreksi resmi. */
class LeaveUsageLifecycleGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Constraint lifecycle fakta pemakaian wajib diuji pada PostgreSQL.');
        }

        Carbon::setTestNow('2026-08-26 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_raw_approved_request_tidak_dapat_ditutup_sebagai_cancelled_atau_superseded(): void
    {
        $record = $this->approvedRequestFact();

        foreach ([LeaveUsageRecord::STATUS_CANCELLED, LeaveUsageRecord::STATUS_SUPERSEDED] as $status) {
            $this->assertLifecycleMutationRejected(
                fn (): int => DB::table('leave_usage_records')->where('id', $record->id)->update([
                    'record_status' => $status,
                    'correction_reason' => "Mutasi raw approved_request menjadi {$status}.",
                    'updated_at' => now(),
                ]),
                'approved_request',
            );
        }
    }

    public function test_raw_pembatalan_manual_tanpa_ledger_dan_audit_satu_transaksi_ditolak(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $record = $this->manualRecord($actor);

        $this->assertLifecycleMutationRejected(
            fn (): int => DB::table('leave_usage_records')->where('id', $record->id)->update([
                'record_status' => LeaveUsageRecord::STATUS_CANCELLED,
                'correction_reason' => 'Pembatalan raw tanpa efek resmi.',
                'updated_at' => now(),
            ]),
            'efek resmi',
        );
    }

    public function test_service_pembatalan_manual_memenuhi_ledger_dan_audit_satu_transaksi(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $record = $this->manualRecord($actor);

        $cancelled = app(LeaveUsageRecordService::class)->cancel(
            $record,
            'Pembatalan resmi untuk uji guard lifecycle.',
            $actor,
        );
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertSame(LeaveUsageRecord::STATUS_CANCELLED, $cancelled->record_status);
    }

    private function approvedRequestFact(): LeaveUsageRecord
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->adminKepegawaian()->create();
        $leaveType = $this->nonAnnualType();
        $request = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-08-20',
            'tanggal_selesai' => '2026-08-20',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture approved_request immutable.',
            'status' => 'disetujui',
        ]);

        return LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
            'leave_request_id' => $request->id,
            'usage_year' => 2026,
            'effective_date' => '2026-08-20',
            'start_date' => '2026-08-20',
            'end_date' => '2026-08-20',
            'workdays' => 1,
            'administrative_note' => 'Fixture approved_request immutable.',
            'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
            'recorded_by' => $actor->id,
        ]);
    }

    private function manualRecord(User $actor): LeaveUsageRecord
    {
        return app(LeaveUsageRecordService::class)->recordManual(
            Employee::factory()->create(),
            $this->nonAnnualType(),
            '2026-08-20',
            '2026-08-20',
            1,
            'Fixture fakta manual untuk guard lifecycle.',
            null,
            'GUARD/2026/001',
            $this->validManualApprovalStepData(),
            $actor,
            [],
        );
    }

    private function nonAnnualType(): RefJenisCuti
    {
        return RefJenisCuti::query()->firstOrCreate(
            ['code' => 'guard-lifecycle-'.Str::lower(Str::random(8))],
            [
                'nama' => 'Cuti Guard Lifecycle',
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ],
        );
    }

    private function assertLifecycleMutationRejected(callable $mutation, string $message): void
    {
        try {
            DB::transaction(function () use ($mutation): void {
                $mutation();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Mutasi lifecycle raw tanpa kontrak domain seharusnya ditolak.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
