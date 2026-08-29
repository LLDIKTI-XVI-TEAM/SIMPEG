<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;

/**
 * Halaman status untuk akun yang identitas atau keaktifannya belum terverifikasi.
 *
 * Middleware mengarahkan pegawai nonaktif, user tanpa mapping Employee, dan
 * status yang tidak dapat diklasifikasikan ke halaman aman ini hingga data akun
 * diperbaiki oleh Admin Kepegawaian.
 */
class InactiveEmployeeAccountController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();

        return view('auth.inactive-employee-account', [
            'employee' => $user?->employee,
            'statusNote' => session()->pull('status_note', $user?->employee?->status_note),
        ]);
    }
}
