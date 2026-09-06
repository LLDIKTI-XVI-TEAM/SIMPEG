<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Regresi PostgreSQL untuk FK recorded_by pada fakta pemakaian cuti yang append-only. */
class LeaveUsageRecordedByForeignKeyNullingTest extends TestCase
{
    use RefreshDatabase;

    public function test_penghapusan_user_hanya_menullkan_recorded_by_fakta_pemakaian(): void
    {
        [$record, $actor] = $this->usageRecordFixture();

        $this->assertParentDeletionOnlyNulls('leave_usage_records', $record->id, 'recorded_by', $actor);
    }

    public function test_update_manual_recorded_by_fakta_ditolak_selama_user_masih_ada(): void
    {
        [$record, $actor] = $this->usageRecordFixture();

        $this->assertManualNullingRejected('leave_usage_records', $record->id);
        $this->assertSame($actor->id, $record->fresh()->recorded_by);
    }

    /** @return array{LeaveUsageRecord, User} */
    private function usageRecordFixture(): array
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->create();
        $leaveType = RefJenisCuti::query()->create([
            'nama' => 'Cuti FK Recorded By '.Str::random(8),
            'code' => 'fk-recorded-by-'.Str::lower(Str::random(8)),
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $record = LeaveUsageRecord::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
            'usage_year' => 2026,
            'effective_date' => '2026-08-20',
            'start_date' => '2026-08-20',
            'end_date' => '2026-08-20',
            'workdays' => 1,
            'administrative_note' => 'Fixture FK actor fakta pemakaian.',
            'recorded_by' => $actor->id,
        ]);

        return [$record, $actor];
    }

    private function assertParentDeletionOnlyNulls(string $table, string $id, string $foreignKey, User $actor): void
    {
        $before = (array) DB::table($table)->where('id', $id)->sole();

        $this->assertTrue($actor->delete());

        $after = (array) DB::table($table)->where('id', $id)->sole();
        $this->assertNull($after[$foreignKey]);
        unset($before[$foreignKey], $after[$foreignKey]);
        $this->assertSame($before, $after);
    }

    private function assertManualNullingRejected(string $table, string $id): void
    {
        try {
            DB::transaction(fn (): bool => DB::table($table)->where('id', $id)->update(['recorded_by' => null]) === 1);
            $this->fail("Pengosongan recorded_by manual pada {$table} seharusnya ditolak.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
    }
}
