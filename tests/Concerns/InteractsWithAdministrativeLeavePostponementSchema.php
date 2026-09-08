<?php

namespace Tests\Concerns;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Fixture raw SQL bersama tanpa mengubah isolasi transaksi masing-masing kelas pengujian. */
trait InteractsWithAdministrativeLeavePostponementSchema
{
    /** Fixture fakta resmi sengaja tidak memakai service reversal agar kontrak raw SQL diuji mandiri. */
    private function fixture(string $status = 'disetujui', array $factOverrides = []): array
    {
        $employee = Employee::factory()->create();
        $actor = User::factory()->create();
        $type = RefJenisCuti::query()->create([
            'nama' => 'Cuti Kontrak Administratif',
            'code' => 'administratif-'.Str::lower(Str::random(8)),
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $type->id,
            'tanggal_mulai' => '2026-10-05',
            'tanggal_selesai' => '2026-10-05',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Alasan pengajuan yang tetap historis.',
            'status' => $status,
        ]);
        $record = LeaveUsageRecord::query()->create(array_replace([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'source_type' => 'approved_request',
            'leave_request_id' => $leave->id,
            'usage_year' => 2026,
            'effective_date' => '2026-10-05',
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-05',
            'workdays' => 1,
            'administrative_note' => 'Snapshot fakta persetujuan.',
            'record_status' => 'active',
            'recorded_by' => $actor->id,
        ], $factOverrides));

        return [$leave, $record, $actor];
    }

    private function decision(User $actor): array
    {
        return [
            'status' => 'ditangguhkan_administratif',
            'administratively_postponed_at' => '2026-09-06 09:00:00',
            'administratively_postponed_by' => $actor->id,
            'administrative_postponement_reason' => 'Periode tidak dapat dilaksanakan.',
        ];
    }

    private function postpone(LeaveRequest $leave, User $actor): void
    {
        DB::table('leave_requests')->where('id', $leave->id)->update($this->decision($actor));
    }

    private function cancelWithEffects(LeaveUsageRecord $record, User $actor, bool $ledger = true, bool $audit = true): void
    {
        DB::table('leave_usage_records')->where('id', $record->id)->update([
            'record_status' => 'cancelled',
            'correction_reason' => 'Pemakaian dibatalkan karena penangguhan administratif.',
        ]);
        $this->recordEffects($record, $actor, $ledger, $audit);
    }

    private function recordEffects(LeaveUsageRecord $record, User $actor, bool $ledger = true, bool $audit = true): void
    {
        if ($ledger) {
            LeaveBalanceLedger::query()->create([
                'employee_id' => $record->employee_id,
                'leave_request_id' => $record->leave_request_id,
                'tahun' => 2026,
                'event_type' => 'usage_fact_cancelled',
                'amount' => 0,
                'reason' => 'Pemakaian dibatalkan karena penangguhan administratif.',
                'metadata' => ['usage_record_id' => $record->id],
                'created_by' => $actor->id,
                'occurred_at' => now(),
            ]);
        }
        if ($audit) {
            AuditLog::query()->create([
                'user_id' => $actor->id,
                'event' => 'UPDATE',
                'auditable_type' => 'LeaveUsageRecord',
                'auditable_id' => $record->id,
                'old_values' => ['record_status' => 'active'],
                'new_values' => ['record_status' => 'cancelled'],
            ]);
        }
    }

    private function assertRejected(callable $mutation, string $message): void
    {
        try {
            DB::transaction(function () use ($mutation): void {
                $mutation();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Mutasi raw di luar kontrak administratif seharusnya ditolak.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
