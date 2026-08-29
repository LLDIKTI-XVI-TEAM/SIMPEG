<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memblokir akses fitur saat identitas atau status aktif pegawai tidak terverifikasi.
 *
 * Seluruh role wajib memiliki relasi Employee yang dapat ditemukan dan status
 * dengan kelompok aktif yang dikenali. Kondisi null, relasi hilang, status invalid,
 * atau status nonaktif diperlakukan fail-closed; hanya halaman status dan logout
 * yang tetap dapat digunakan sampai Admin Kepegawaian memperbaiki akun.
 */
class EnsureActiveEmployeeAccount
{
    /** Route yang tetap boleh diakses dalam keadaan nonaktif. */
    private const ALLOWED_ROUTES = [
        'status-akun',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $routeName = $request->route()?->getName() ?? '';
        if (in_array($routeName, self::ALLOWED_ROUTES, true)
            || $request->is('logout', 'status-akun', 'login', 'callback*')) {
            return $next($request);
        }

        $employee = $user->employee_id === null
            ? null
            : Employee::query()->whereKey($user->employee_id)->first();

        // K-STATUS-05: fail-closed — relasi status yang hilang/invalid tidak boleh
        // dianggap aktif. Bila user menunjuk employee yang tidak ditemukan atau
        // statusnya tidak dapat ditentukan, akses diblokir ke halaman status-akun.
        if ($employee === null || ! $employee->isActive()) {
            // Simpan pesan nonaktif agar halaman status-akun dapat menampilkannya.
            if ($employee !== null) {
                session(['status_note' => $employee->status_note]);
            }

            return redirect()->route('status-akun');
        }

        return $next($request);
    }
}
