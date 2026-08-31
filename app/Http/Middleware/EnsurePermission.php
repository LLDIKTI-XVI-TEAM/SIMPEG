<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memastikan pengguna memiliki minimal satu dari permission yang diizinkan untuk route.
 * Otorisasi level permission ditegakkan di backend; UI tidak boleh menjadi satu-satunya penjaga akses.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        // Fail-closed: tanpa pengguna terautentikasi, tolak akses.
        if (! $user) {
            abort(403, 'Anda tidak memiliki hak akses untuk fitur ini.');
        }

        // Super Admin memiliki akses penuh ke seluruh permission sistem
        if ($user->getEffectiveRole() === 'super_admin') {
            return $next($request);
        }

        // Cukup satu permission cocok agar route mendukung beberapa role/permission alternatif.
        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        abort(403, 'Anda tidak memiliki hak akses untuk fitur ini.');
    }
}
