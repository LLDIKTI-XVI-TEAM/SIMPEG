<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Anda tidak memiliki hak akses untuk fitur ini.');
        }

        $effectiveRole = $user->getEffectiveRole();

        if ($effectiveRole === null || $effectiveRole === '' || ! Role::where('name', $effectiveRole)->exists()) {
            abort(403, 'Akun Anda belum memiliki role SIMPEG. Hubungi Admin.');
        }

        if (! in_array($effectiveRole, $roles, true)) {
            abort(403, 'Anda tidak memiliki hak akses untuk fitur ini.');
        }

        return $next($request);
    }
}
