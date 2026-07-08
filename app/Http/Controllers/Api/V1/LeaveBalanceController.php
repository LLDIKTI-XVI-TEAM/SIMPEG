<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    /**
     * API: Mengembalikan saldo dan riwayat cuti milik pengguna login.
     */
    public function showMyBalance(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'message' => 'Akun pengguna belum ter-mapping ke data pegawai.',
            ], 404);
        }

        $tahun = (int) $request->query('tahun', now()->year);

        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('tahun', $tahun)
            ->first();

        $history = LeaveRequest::where('employee_id', $employee->id)
            ->with(['jenisCuti', 'approvals.approver', 'steps'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'balance' => $balance ? [
                'jatah_awal' => $balance->jatah_awal,
                'carry_over' => $balance->carry_over,
                'terpakai' => $balance->terpakai,
                'sisa' => $balance->sisa,
                'tahun' => $balance->tahun,
            ] : null,
            'history' => $history->map(fn (LeaveRequest $lr) => [
                'id' => $lr->id,
                'jenis_cuti' => $lr->jenisCuti?->nama,
                'tanggal_mulai' => $lr->tanggal_mulai->toDateString(),
                'tanggal_selesai' => $lr->tanggal_selesai->toDateString(),
                'jumlah_hari_kerja' => $lr->jumlah_hari_kerja,
                'alasan' => $lr->alasan,
                'status' => $lr->status,
                'current_step' => $lr->steps->firstWhere('status', 'active')?->step_order,
                'created_at' => $lr->created_at->toIso8601String(),
            ]),
        ]);
    }
}
