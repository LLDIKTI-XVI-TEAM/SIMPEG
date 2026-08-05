<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Menyusun saldo dan riwayat cuti pribadi agar controller hanya menjadi adapter HTTP.
 */
class ShowMyLeaveBalanceAction
{
    public function __construct(private readonly PreviewLeaveBalanceAction $preview) {}

    /**
     * Menyediakan data halaman Blade dengan pagination riwayat legacy tetap utuh.
     *
     * @return array{balance:?LeaveBalance,history:LengthAwarePaginator<int, LeaveRequest>,rule5Active:bool}
     */
    public function forWeb(Employee $employee, Carbon $asOf): array
    {
        $balance = $this->balanceForYear($employee, $asOf->year);
        $preview = $this->preview->execute($employee, $asOf);

        return [
            'balance' => $balance,
            'history' => LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->with('jenisCuti')
                ->orderByDesc('created_at')
                ->paginate(10),
            // Flag preview dipakai ulang agar halaman tidak menjalankan query Rule 5 kedua.
            'rule5Active' => $preview['rule_5_active'],
        ];
    }

    /**
     * Menyusun payload API legacy dengan `sisa` tercatat dan `sisa_efektif` additive.
     * Riwayat sengaja tidak dipaginasi karena kontrak endpoint lama mengembalikan koleksi utuh.
     *
     * @return array{
     *     balance:?array{jatah_awal:int,carry_over:int,terpakai:int,sisa:int,sisa_efektif:int,tahun:int},
     *     history:Collection
     * }
     */
    public function forApi(Employee $employee, int $tahun): array
    {
        $balance = $this->balanceForYear($employee, $tahun);
        $preview = $balance === null
            ? null
            : $this->preview->execute($employee, Carbon::create($tahun, 1, 1)->startOfDay());

        return [
            'balance' => $balance === null ? null : [
                'jatah_awal' => $balance->jatah_awal,
                'carry_over' => $balance->carry_over,
                'terpakai' => $balance->terpakai,
                'sisa' => $balance->sisa,
                'sisa_efektif' => $preview['saldo_dapat_diajukan'],
                'tahun' => $balance->tahun,
            ],
            'history' => LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->with(['jenisCuti', 'approvals.approver', 'steps'])
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (LeaveRequest $leaveRequest): array => [
                    'id' => $leaveRequest->id,
                    'jenis_cuti' => $leaveRequest->jenisCuti?->nama,
                    'tanggal_mulai' => $leaveRequest->tanggal_mulai->toDateString(),
                    'tanggal_selesai' => $leaveRequest->tanggal_selesai->toDateString(),
                    'jumlah_hari_kerja' => $leaveRequest->jumlah_hari_kerja,
                    'alasan' => $leaveRequest->alasan,
                    'status' => $leaveRequest->status,
                    'current_step' => $leaveRequest->steps->firstWhere('status', 'active')?->step_order,
                    'created_at' => $leaveRequest->created_at->toIso8601String(),
                ]),
        ];
    }

    /** Mengambil summary tercatat tanpa mengubah saldo atau entitlement. */
    private function balanceForYear(Employee $employee, int $tahun): ?LeaveBalance
    {
        return LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', $tahun)
            ->first();
    }
}
