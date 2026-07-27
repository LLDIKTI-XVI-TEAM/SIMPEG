<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\AdjustLeaveBalanceAction;
use App\Actions\Cuti\SetOpeningLeaveBalanceAction;
use App\Actions\Cuti\ShowLeaveBalanceAdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\AdjustLeaveBalanceRequest;
use App\Http\Requests\Cuti\ListCutiRekapRequest;
use App\Http\Requests\Cuti\OpeningLeaveBalanceRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    /** Menampilkan administrasi saldo dengan data yang disusun Action agar controller tetap tipis. */
    public function administrasi(ListCutiRekapRequest $request, ShowLeaveBalanceAdminAction $action)
    {
        return view('admin.cuti.administrasi-saldo', $action->execute($request->validated()));
    }

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
        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', $tahun)
            ->first();

        $history = LeaveRequest::where('employee_id', $employee->id)
            ->with(['jenisCuti'])
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('admin.cuti.personal-saldo', compact('balance', 'history'));
    }

    /**
     * Menyimpan koreksi saldo dari halaman admin.
     * Route dan request final akan memvalidasi detail input; method ini ada agar gate backend aktif lebih dulu.
     */
    public function storeOpeningBalance(OpeningLeaveBalanceRequest $request, Employee $employee, SetOpeningLeaveBalanceAction $action)
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $payload = $request->validated();

        $action->execute($employee, $payload, $actor);

        return $this->redirectToAdminBalancePanel($employee, (int) $payload['tahun'])
            ->with('success', 'Saldo awal cuti berhasil disimpan.');
    }

    /**
     * Menyimpan koreksi saldo dari halaman admin.
     * Request menangani validasi dan otorisasi; action menjaga controller tetap bebas logika bisnis.
     */
    public function adjust(AdjustLeaveBalanceRequest $request, Employee $employee, AdjustLeaveBalanceAction $action)
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $payload = $request->validated();

        $action->execute($employee, $payload, $actor);

        return $this->redirectToAdminBalancePanel($employee, (int) $payload['tahun'])
            ->with('success', 'Koreksi saldo cuti berhasil disimpan.');
    }

    /**
     * Mengembalikan admin ke panel saldo pegawai yang baru dikoreksi agar konteks audit dan ledger tetap terlihat.
     */
    private function redirectToAdminBalancePanel(Employee $employee, int $tahun)
    {
        return redirect()->route('cuti.saldo.administrasi', [
            'pegawai' => $employee->id,
            'periode' => $tahun,
        ]);
    }
}
