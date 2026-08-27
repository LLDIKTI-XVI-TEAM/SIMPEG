<?php

namespace App\Services\Cuti;

use App\Models\AnnualLeaveAnniversarySchedulerState;
use Illuminate\Support\Facades\DB;

final class AnnualLeaveAnniversaryCursorService
{
    /**
     * Membaca checkpoint di bawah row lock agar pembacaan tidak bertabrakan dengan kemajuan run lain.
     */
    public function current(): ?string
    {
        return DB::transaction(function (): ?string {
            $state = AnnualLeaveAnniversarySchedulerState::query()
                ->whereKey(AnnualLeaveAnniversarySchedulerState::KEY)
                ->lockForUpdate()
                ->sole();

            return $state->cursor_employee_id;
        });
    }

    /**
     * Memajukan checkpoint dalam transaksi tersendiri setelah setiap kandidat, termasuk yang gagal.
     */
    public function advance(string $employeeId): void
    {
        DB::transaction(function () use ($employeeId): void {
            $state = AnnualLeaveAnniversarySchedulerState::query()
                ->whereKey(AnnualLeaveAnniversarySchedulerState::KEY)
                ->lockForUpdate()
                ->sole();

            $state->forceFill([
                'cursor_employee_id' => $employeeId,
                'cursor_advanced_at' => now(),
            ])->save();
        });
    }
}
