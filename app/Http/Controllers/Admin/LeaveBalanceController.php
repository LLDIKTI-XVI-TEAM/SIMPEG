<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ShowLeaveBalanceAdminAction;
use App\Actions\Cuti\ShowMyLeaveBalanceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\LeaveBalanceAdminPageRequest;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    /** Menampilkan administrasi saldo dengan data yang disusun Action agar controller tetap tipis. */
    public function administrasi(LeaveBalanceAdminPageRequest $request, ShowLeaveBalanceAdminAction $action)
    {
        return view('admin.cuti.administrasi-saldo', $action->execute($request->validated()));
    }

    /**
     * Web: Menampilkan halaman saldo cuti pribadi untuk pengguna login.
     */
    public function showMyBalanceWeb(Request $request, ShowMyLeaveBalanceAction $action)
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return redirect()->route('dashboard')
                ->with('error', 'Akun Anda belum ter-mapping ke data pegawai.');
        }

        return view('admin.cuti.personal-saldo', $action->forWeb($employee, now()));
    }
}
