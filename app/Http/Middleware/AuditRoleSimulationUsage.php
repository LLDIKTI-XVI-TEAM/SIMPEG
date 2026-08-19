<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mencatat jejak audit ketika role sementara (simulasi) benar-benar digunakan.
 *
 * AC-6 mensyaratkan switch, penggunaan role sementara, dan revert seluruhnya meninggalkan
 * jejak audit. Switch/revert sudah dicatat oleh SwitchRoleAction/RevertRoleAction; middleware
 * ini menutup celah "penggunaan":
 *
 * - Pencatatan dijalankan SETELAH request diotorisasi dan hanya untuk respons sukses
 *   (2xx/3xx) sehingga 401/403/404/500 tidak diklaim sebagai penggunaan. Berlaku untuk
 *   request baca (GET/HEAD) maupun mutasi (POST/PUT/PATCH/DELETE) yang berhasil.
 * - Penulisan memakai logOrFail() (fail-closed): bila jejak usage gagal ditulis, request
 *   tidak disajikan sebagai penggunaan simulasi yang sah.
 * - Rute polling notifikasi otomatis (bukan interaksi user) dikecualikan dari pencatatan.
 */
class AuditRoleSimulationUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldAuditUsage($request, $response)) {
            return $response;
        }

        /** @var User $user */
        $user = $request->user();

        AuditService::logOrFail(
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
                'status' => $response->getStatusCode(),
            ],
            $request,
        );

        return $response;
    }

    private function shouldAuditUsage(Request $request, Response $response): bool
    {
        $user = $request->user();

        if (! $user instanceof User || ! filled($user->temporary_role)) {
            return false;
        }

        // Polling notifikasi otomatis (notification bell) bukan interaksi pengguna yang sah;
        // sama seperti SessionTimeoutMessage, dikecualikan agar tab yang dibiarkan terbuka
        // tidak membanjiri audit log dengan klaim penggunaan role sementara tanpa interaksi.
        if ($request->routeIs('api.v1.notifikasi.index', 'api.v1.notifikasi.jumlah-belum-dibaca')) {
            return false;
        }

        // Setelah request diotorisasi dan merespons sukses (2xx/3xx), baik baca (GET/HEAD) maupun
        // mutasi (POST/PUT/PATCH/DELETE) dianggap sebagai penggunaan role sementara yang sah (AC-6).
        return $response->getStatusCode() < 400;
    }
}
