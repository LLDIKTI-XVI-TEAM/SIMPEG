<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Regresi PostgreSQL untuk menjaga append-only ledger tetap kompatibel dengan FK nullOnDelete. */
class LeaveBalanceLedgerForeignKeyNullingTest extends TestCase
{
    use RefreshDatabase;

    public function test_penghapusan_actor_hanya_menullkan_created_by(): void
    {
        [$ledger, $actor] = $this->ledgerFixture();

        $this->assertParentDeletionOnlyNulls($ledger, 'created_by', fn (): bool => $actor->delete());
    }

    public function test_penghapusan_pengajuan_hanya_menullkan_leave_request_id(): void
    {
        [$ledger, , $leaveRequest] = $this->ledgerFixture();

        $this->assertParentDeletionOnlyNulls(
            $ledger,
            'leave_request_id',
            fn (): bool => $leaveRequest->delete(),
        );
    }

    public function test_penghapusan_projection_hanya_menullkan_leave_balance_id(): void
    {
        [$ledger, , , $balance] = $this->ledgerFixture();

        $this->assertParentDeletionOnlyNulls($ledger, 'leave_balance_id', fn (): bool => $balance->delete());
    }

    public function test_update_fk_manual_tetap_ditolak_selama_parent_masih_ada(): void
    {
        [$ledger] = $this->ledgerFixture();

        $this->assertStatementRejected(
            "UPDATE leave_balance_ledger SET created_by = NULL WHERE id = '{$ledger->id}'",
        );
        $this->assertNotNull($ledger->fresh()->created_by);
    }

    public function test_update_substansi_ledger_tetap_ditolak(): void
    {
        [$ledger] = $this->ledgerFixture();

        $this->assertStatementRejected(
            "UPDATE leave_balance_ledger SET amount = amount + 1 WHERE id = '{$ledger->id}'",
        );
        $this->assertSame(12, $ledger->fresh()->amount);
    }

    /**
     * @return array{LeaveBalanceLedger, User, LeaveRequest, LeaveBalance}
     */
    private function ledgerFixture(): array
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->create();
        $leaveType = RefJenisCuti::query()->create([
            'nama' => 'Cuti Ledger FK '.Str::random(8),
            'code' => 'ledger-fk-'.Str::lower(Str::random(8)),
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leaveRequest = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-08-20',
            'tanggal_selesai' => '2026-08-20',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture FK ledger append-only.',
            'status' => 'disetujui',
        ]);
        $balance = LeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'sisa' => 12,
            'sisa_tahun_berjalan' => 12,
        ]);
        $ledger = LeaveBalanceLedger::query()->create([
            'employee_id' => $employee->id,
            'leave_request_id' => $leaveRequest->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED,
            'amount' => 12,
            'source_year' => 2025,
            'reason' => 'Snapshot ledger yang hanya boleh kehilangan referensi parent.',
            'dedup_key' => 'ledger-fk-'.Str::uuid(),
            'metadata' => ['fixture' => 'foreign-key-nulling'],
            'created_by' => $actor->id,
            'occurred_at' => '2026-08-20 10:00:00',
        ]);

        return [$ledger, $actor, $leaveRequest, $balance];
    }

    private function assertParentDeletionOnlyNulls(
        LeaveBalanceLedger $ledger,
        string $foreignKey,
        callable $deleteParent,
    ): void {
        $before = (array) DB::table('leave_balance_ledger')->where('id', $ledger->id)->sole();

        $this->assertTrue($deleteParent());

        $after = (array) DB::table('leave_balance_ledger')->where('id', $ledger->id)->sole();
        $this->assertNull($after[$foreignKey]);
        unset($before[$foreignKey], $after[$foreignKey]);
        $this->assertSame($before, $after);
    }

    private function assertStatementRejected(string $statement): void
    {
        try {
            DB::transaction(fn (): bool => DB::statement($statement));
            $this->fail("Statement seharusnya ditolak: {$statement}");
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }
}
