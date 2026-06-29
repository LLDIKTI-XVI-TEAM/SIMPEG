<?php

namespace App\Http\Controllers;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    /**
     * API: Return personal leave balance and request history for the logged-in user.
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
            ->with(['jenisCuti', 'approvals.approver'])
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
                'current_stage' => $lr->current_stage,
                'created_at' => $lr->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Web: Show personal leave balance page for the logged-in user.
     */
    public function showMyBalanceWeb(Request $request)
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return redirect()->route('dashboard')
                ->with('error', 'Akun Anda belum ter-mapping ke data pegawai.');
        }

        $tahun = (int) now()->year;
        $balance = LeaveBalance::firstOrCreate(
            ['employee_id' => $employee->id, 'tahun' => $tahun],
            ['jatah_awal' => 12, 'carry_over' => 0, 'terpakai' => 0, 'sisa' => 12]
        );

        $history = LeaveRequest::where('employee_id', $employee->id)
            ->with(['jenisCuti'])
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('admin.cuti.personal-saldo', compact('balance', 'history'));
    }
}
