<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mencatat jejak audit ketika role sementara (simulasi) digunakan untuk request baca.
 *
 * AC-6 mensyaratkan switch, penggunaan role sementara, dan revert seluruhnya meninggalkan
 * jejak audit. Switch/revert sudah dicatat oleh SwitchRoleAction/RevertRoleAction; middleware
 * ini menutup celah "penggunaan": setiap request baca (GET/HEAD) yang terautentikasi dan
 * diotorisasi saat simulasi aktif dicatat sebagai ROLE_SIMULATION_USAGE. Mutation (POST, dll.)
 * sengaja dilewati karena sudah memiliki audit event tersendiri.
 */
class AuditRoleSimulationUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && filled($user->temporary_role)) {
            AuditService::log(
                'ROLE_SIMULATION_USAGE',
                'User',
                $user->id,
                null,
                [
                    'original_role' => $user->role,
                    'effective_role' => $user->getEffectiveRole(),
                    'route' => $request->route()?->getName() ?? $request->path(),
                    'http_method' => $request->method(),
                    'path' => $request->path(),
                ],
                $request,
            );
        }

        return $next($request);
    }
}
