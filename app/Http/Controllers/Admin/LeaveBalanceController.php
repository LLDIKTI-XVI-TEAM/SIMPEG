<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    /**
     * Web: Menampilkan halaman saldo cuti pribadi untuk pengguna login.
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
