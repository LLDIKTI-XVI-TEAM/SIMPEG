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
 *   (2xx/3xx) sehingga 401/403/404/500 tidak diklaim sebagai penggunaan.
 * - Rute kontrol/otomatis (revert-role, polling notifikasi, polling status impor) bukan
 *   interaksi pengguna sah dan tidak dicatat.
 * - Request baca (GET/HEAD): logOrFail() fail-closed, karena membaca belum melakukan mutasi
 *   domain yang perlu di-rollback; pemakaian tanpa jejak dianggap tidak sah.
 * - Request mutasi (POST/PUT/PATCH/DELETE): log() fail-open. Efek domain mutasi sudah dicatat
 *   di dalam transaksi oleh Action masing-masing (dengan konteks _simulation); pencatatan
 *   usage tambahan di sini tidak boleh mengubah mutasi yang sudah berhasil menjadi 500
 *   (yang membuat klien mengulang operasi) ketika penulisan audit pasca-respons gagal.
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

        $payload = [
            'original_role' => $user->role,
            'effective_role' => $user->getEffectiveRole(),
            'route' => $request->route()?->getName() ?? $request->path(),
            'http_method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
        ];

        if ($this->isReadRequest($request)) {
            AuditService::logOrFail('ROLE_SIMULATION_USAGE', 'User', $user->id, null, $payload, $request);
        } else {
            AuditService::log('ROLE_SIMULATION_USAGE', 'User', $user->id, null, $payload, $request);
        }

        return $response;
    }

    private function shouldAuditUsage(Request $request, Response $response): bool
    {
        $user = $request->user();

        if (! $user instanceof User || ! filled($user->temporary_role)) {
            return false;
        }

        // Rute kontrol/otomatis yang bukan interaksi pengguna sah — dikecualikan dari klaim
        // penggunaan role sementara agar jejak tidak membanjiri audit_log maupun menyatakan
        // simulasi masih aktif setelah revert/laporan status timer.
        if ($request->routeIs(
            'revert-role',
            'api.v1.notifikasi.index',
            'api.v1.notifikasi.jumlah-belum-dibaca',
            'pegawai.import.status',
        )) {
            return false;
        }

        // Setelah request diotorisasi dan merespons sukses (2xx/3xx).
        return $response->getStatusCode() < 400;
    }

    private function isReadRequest(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD'], true);
    }
}
