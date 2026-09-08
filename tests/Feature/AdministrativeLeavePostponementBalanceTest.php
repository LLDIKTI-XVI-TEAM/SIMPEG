<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\Cuti\LeaveUsageRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\RecordsHistoricalAnnualLeaveUsage;
use Tests\TestCase;

class AdministrativeLeavePostponementBalanceTest extends TestCase
{
    use RecordsHistoricalAnnualLeaveUsage;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-06 09:00:00', 'Asia/Makassar'));
        $this->seedReferenceData();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pembalikan_cuti_mendatang_memulihkan_bucket_tanpa_mengubah_fakta_lama(): void
    {
        [$employee, $actor] = $this->actors();
        $this->baseline($employee, $actor);
        $leave = $this->approved($employee, $actor);
        $fact = $leave->usageRecord;
        $original = $fact->only(['id', 'source_type', 'employee_id', 'leave_request_id', 'usage_year', 'effective_date', 'start_date', 'end_date', 'workdays']);
        $this->assertSame(22, $this->balance($employee)->sisa);

        $reversed = $this->reverse($leave, $actor);

        $this->assertSame(LeaveUsageRecord::STATUS_CANCELLED, $reversed->record_status);
        $this->assertEquals($original, $reversed->only(array_keys($original)));
        $balance = $this->balance($employee);
        $this->assertSame([6, 6, 12, 0, 24], [$balance->sisa_n2, $balance->sisa_n1, $balance->sisa_tahun_berjalan, $balance->terpakai, $balance->sisa]);
        $this->assertSame(1, LeaveBalanceLedger::query()->where('dedup_key', 'usage:usage_fact_cancelled:'.$fact->id)->count());
        $this->assertSame(1, AuditLog::query()->where('auditable_type', 'LeaveUsageRecord')->where('auditable_id', $fact->id)->where('event', 'UPDATE')->count());
        $this->assertStringNotContainsString('Alasan administratif privat', (string) $reversed->toJson());
        $this->assertFalse(LeaveBalanceLedger::query()->where('reason', 'like', '%Alasan administratif privat%')->exists());
    }

    public function test_fakta_approved_dan_manual_lama_tetap_terpakai_saat_cuti_mendatang_dibatalkan(): void
    {
        [$employee, $actor] = $this->actors();
        $this->recordHistoricalAnnualUsage($employee, [2026 => 2], $actor, 'Cuti historis yang sudah berlangsung.');
        $manual = LeaveUsageRecord::query()->where('employee_id', $employee->id)
            ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)->sole();
        $past = $this->approved($employee, $actor, 'tahunan', '2026-08-03', '2026-08-04');
        $manualBefore = $manual->getRawOriginal();
        $pastBefore = $past->usageRecord->getRawOriginal();
        $future = $this->approved($employee, $actor);
        $this->assertSame(6, $this->balance($employee)->terpakai);

        $this->reverse($future, $actor);

        $this->assertSame(4, $this->balance($employee)->terpakai);
        $this->assertSame(20, $this->balance($employee)->sisa);
        $this->assertSame($manualBefore, $manual->fresh()->getRawOriginal());
        $this->assertSame($pastBefore, $past->usageRecord->fresh()->getRawOriginal());
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $manual->fresh()->record_status);
        $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $past->usageRecord->fresh()->record_status);
        $this->assertDatabaseCount('leave_usage_records', 3);
    }

    public function test_cuti_besar_dibatalkan_memulihkan_hak_tahunan_sesuai_rule_5(): void
    {
        [$employee, $actor] = $this->actors();
        $this->baseline($employee, $actor);
        $leave = $this->approved($employee, $actor, 'besar');
        $this->assertSame(0, app(LeaveBalanceService::class)->availableFor($employee, 2026, now()));
        $this->assertSame(12, $this->balance($employee)->sisa_tahun_berjalan);

        $this->reverse($leave, $actor);

        $this->assertSame(24, app(LeaveBalanceService::class)->availableFor($employee, 2026, now()));
        $this->assertSame(12, $this->balance($employee)->sisa_tahun_berjalan);
    }

    public function test_cuti_non_tahunan_tidak_menghasilkan_kredit_saldo_tahunan(): void
    {
        [$employee, $actor] = $this->actors();
        $this->baseline($employee, $actor);
        $leave = $this->approved($employee, $actor, 'sakit');
        $before = $this->balance($employee)->toArray();
        $projectionCount = LeaveBalanceLedger::query()->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)->count();

        $this->reverse($leave, $actor);

        $this->assertSame($before, $this->balance($employee)->toArray());
        $this->assertSame($projectionCount, LeaveBalanceLedger::query()->where('event_type', LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED)->count());
        $this->assertSame(LeaveUsageRecord::STATUS_CANCELLED, $leave->usageRecord->fresh()->record_status);
    }

    public function test_service_tidak_membalik_fakta_tanpa_keputusan_administratif(): void
    {
        [$employee, $actor] = $this->actors();
        $this->baseline($employee, $actor);
        $leave = $this->approved($employee, $actor);

        try {
            DB::transaction(fn () => app(LeaveUsageRecordService::class)->reverseApprovedRequest($leave, $actor));
            $this->fail('Pembalikan tanpa keputusan harus ditolak.');
        } catch (ValidationException) {
            $this->assertSame(LeaveUsageRecord::STATUS_ACTIVE, $leave->usageRecord->fresh()->record_status);
            $this->assertSame(22, $this->balance($employee)->sisa);
        }
    }

    /** @return array{Employee, User} */
    private function actors(): array
    {
        $employee = Employee::factory()->create(['jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->firstOrFail()->id]);
        Appointment::create(['employee_id' => $employee->id, 'jenis_pengangkatan' => 'PNS', 'tmt_pengangkatan' => '2020-01-01']);

        return [$employee, User::factory()->adminKepegawaian()->create()];
    }

    private function baseline(Employee $employee, User $actor): void
    {
        $this->recordHistoricalAnnualUsage($employee, [2024 => 0, 2025 => 0, 2026 => 0], $actor, 'Pemakaian tahunan awal.');
    }

    private function approved(Employee $employee, User $actor, string $type = 'tahunan', string $start = '2026-09-14', string $end = '2026-09-15'): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::query()->where('code', $type)->firstOrFail()->id,
            'tanggal_mulai' => $start,
            'tanggal_selesai' => $end,
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengajuan untuk pengujian saldo.',
            'status' => 'disetujui',
        ]);
        app(LeaveUsageRecordService::class)->recordApprovedRequest($leave, $actor);

        return $leave;
    }

    private function reverse(LeaveRequest $leave, User $actor): LeaveUsageRecord
    {
        return DB::transaction(function () use ($leave, $actor): LeaveUsageRecord {
            $leave->forceFill([
                'status' => 'ditangguhkan_administratif',
                'administratively_postponed_at' => now(),
                'administratively_postponed_by' => $actor->id,
                'administrative_postponement_reason' => 'Alasan administratif privat untuk audit.',
            ])->save();

            return app(LeaveUsageRecordService::class)->reverseApprovedRequest($leave, $actor);
        });
    }

    private function balance(Employee $employee): LeaveBalance
    {
        return LeaveBalance::query()->where('employee_id', $employee->id)->where('tahun', 2026)->sole();
    }
}
