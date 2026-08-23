<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditService;
use App\Services\TransactionSideEffectManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Mencatat jejak audit ketika role sementara (simulasi) benar-benar digunakan.
 *
 * Switch dan revert dicatat oleh Action masing-masing. Middleware ini melengkapi jejak
 * penggunaan tanpa mengubah identitas atau scope kepemilikan aktor asli:
 *
 * - Pencatatan dijalankan SETELAH request diotorisasi dan hanya untuk respons sukses
 *   (2xx/3xx) sehingga 401/403/404/500 tidak diklaim sebagai penggunaan.
 * - Rute kontrol/otomatis (revert-role, polling notifikasi, polling status impor) bukan
 *   interaksi pengguna sah dan tidak dicatat.
 * - Request baca dicatat fail-closed setelah response sukses.
 * - Request mutasi dibungkus transaksi database; perubahan domain dan audit penggunaan baru
 *   commit bersama sehingga kegagalan audit tidak meninggalkan mutasi tanpa jejak.
 */
class AuditRoleSimulationUsage
{
    public function __construct(private readonly TransactionSideEffectManager $sideEffects) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->requiresAtomicMutationAudit($request)) {
            $this->sideEffects->begin();
            $response = null;

            try {
                DB::transaction(function () use ($request, $next, &$response): Response {
                    // Callback pertama menjadi penanda batas durable. Callback Laravel lain
                    // dapat gagal setelah PDO commit dan tidak boleh memicu cleanup rollback.
                    DB::afterCommit(fn () => $this->sideEffects->markDatabaseCommitted());

                    $response = $next($request);
                    $this->auditSuccessfulUsage($request, $response);

                    return $response;
                });
            } catch (Throwable $exception) {
                if ($this->sideEffects->databaseWasCommitted()) {
                    // Callback pasca-commit tidak dapat membatalkan record sah. Laporkan kegagalan
                    // operasionalnya, tetapi pertahankan respons sukses agar klien tidak me-retry mutasi.
                    $this->commitSideEffects();
                    report($exception);

                    if ($response instanceof Response) {
                        return $response;
                    }
                } else {
                    $this->rollbackSideEffects();
                }

                throw $exception;
            }

            // Penghapusan file lama baru aman setelah audit penggunaan ikut commit.
            $this->commitSideEffects();

            /** @var Response $response */
            return $response;
        }

        $response = $next($request);
        $this->auditSuccessfulUsage($request, $response);

        return $response;
    }

    /** Mutation selama simulasi harus commit bersama audit penggunaannya. */
    private function requiresAtomicMutationAudit(Request $request): bool
    {
        return $this->hasActiveSimulation($request)
            && $this->usesEffectiveRole($request)
            && $this->isMutationRequest($request)
            && ! $this->isExcludedRoute($request);
    }

    /** Menulis audit hanya untuk penggunaan yang lolos authorization dan menghasilkan response sukses. */
    private function auditSuccessfulUsage(Request $request, Response $response): void
    {
        if (! $this->shouldAuditUsage($request, $response)) {
            return;
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
    }

    private function shouldAuditUsage(Request $request, Response $response): bool
    {
        if (! $this->hasActiveSimulation($request)) {
            return false;
        }

        if (! $this->usesEffectiveRole($request)) {
            return false;
        }

        if ($this->isExcludedRoute($request)) {
            return false;
        }

        return $response->getStatusCode() < 400;
    }

    private function hasActiveSimulation(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && filled($user->temporary_role);
    }

    /**
     * Usage hanya bermakna ketika route mengevaluasi role atau permission internal.
     * Route publik dan callback autentikasi tetap melewati grup web, tetapi tidak memakai
     * role efektif sehingga tidak boleh menghasilkan jejak penggunaan semu.
     */
    private function usesEffectiveRole(Request $request): bool
    {
        $middleware = $request->route()?->gatherMiddleware() ?? [];

        foreach ($middleware as $name) {
            if (str_starts_with($name, 'role:')
                || str_starts_with($name, 'permission:')
                || str_starts_with($name, EnsureRole::class.':')
                || str_starts_with($name, EnsurePermission::class.':')) {
                return true;
            }
        }

        return false;
    }

    /** Route otomatis dan pemulihan tidak merepresentasikan penggunaan role tujuan. */
    private function isExcludedRoute(Request $request): bool
    {
        return $request->routeIs(
            'revert-role',
            'api.v1.notifikasi.index',
            'api.v1.notifikasi.jumlah-belum-dibaca',
            'pegawai.import.status',
        );
    }

    private function isMutationRequest(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /** Efek commit bersifat cleanup; kegagalannya dilaporkan tanpa membatalkan respons sah. */
    private function commitSideEffects(): void
    {
        try {
            $this->sideEffects->commit();
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }

    /** Jalankan semua kompensasi rollback tanpa menutupi exception transaksi utama. */
    private function rollbackSideEffects(): void
    {
        try {
            $this->sideEffects->rollback();
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }
}
