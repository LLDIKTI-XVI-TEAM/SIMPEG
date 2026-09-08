<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menjaga lifecycle pada capability yang berorientasi User (inbox/kalender).
 *
 * User tanpa binding Employee tetap dapat menggunakan surface user-context.
 * Namun bila binding sudah ada, Employee tersebut wajib masih valid dan aktif;
 * kondisi nonaktif, status invalid, atau referensi Employee hilang tetap ditolak.
 */
class EnsureUserContextAccountAccessible
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->employee_id === null) {
            return $next($request);
        }

        $employee = Employee::query()->whereKey($user->employee_id)->first();

        if ($employee === null || ! $employee->isActive()) {
            if ($employee !== null) {
                session(['status_note' => $employee->status_note]);
            }

            return redirect()->route('status-akun');
        }

        return $next($request);
    }
}
