<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $p = $user->employee()->with([
            'families',
            'rankHistories.golongan',
            'positionHistories.unitKerja',
            'salaryHistories',
            'disciplineRecords',
            'educationHistories.jenjang',
            'documents',
            'atasanLangsung',
        ])->first();

        // Ambil saldo cuti tahun ini
        $tahun = date('Y');
        $saldoCuti = 0;
        if ($p) {
            $leaveBalance = LeaveBalance::where('employee_id', $p->id)
                ->where('tahun', $tahun)
                ->first();
            if ($leaveBalance) {
                // total_hak + sisa_tahun_sebelumnya - total_terpakai
                $saldoCuti = $leaveBalance->total_hak + $leaveBalance->sisa_tahun_sebelumnya - $leaveBalance->total_terpakai;
            } else {
                $saldoCuti = 12; // default jatah cuti tahunan jika belum dibuat row-nya
            }
        }

        return view('admin.profile.index', compact('p', 'saldoCuti'));
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        return redirect()->route('profil')
            ->with('success', 'Kata sandi Anda berhasil diperbarui.');
    }
}
