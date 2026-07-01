<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SessionTimeoutMessage
{
    private const MESSAGE = 'Sesi Anda telah berakhir. Silakan login kembali.';

    /**
     * Menegakkan idle timeout SIMPEG sebelum payload session native hilang agar audit masih punya identitas user.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $timeoutMinutes = (int) config('session.simpeg_idle_timeout', 30);
        $now = now()->timestamp;
        $lastActivity = $request->session()->get('last_activity_at');

        if (is_int($lastActivity) && ($now - $lastActivity) > ($timeoutMinutes * 60)) {
            $this->auditTimeout($request, $lastActivity, $now);

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->put('simpeg_session_timeout_message', self::MESSAGE);

            if ($request->expectsJson()) {
                return response()->json(['message' => self::MESSAGE], 401);
            }

            return redirect()->route('login');
        }

        if ($this->shouldRefreshActivity($request)) {
            $this->rememberAuthenticatedUser($request, $now);
            $request->session()->put('last_activity_at', $now);
        }

        return $next($request);
    }

    /**
     * Polling notifikasi otomatis tidak dihitung sebagai aktivitas user agar tab terbuka tidak membuat session abadi.
     */
    private function shouldRefreshActivity(Request $request): bool
    {
        return ! $request->routeIs('api.v1.notifikasi.index', 'api.v1.notifikasi.jumlah-belum-dibaca');
    }

    /**
     * Menyimpan identitas terakhir selama session masih valid agar audit timeout bisa ditulis sebelum invalidasi.
     */
    private function rememberAuthenticatedUser(Request $request, int $now): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        $request->session()->put([
            'last_authenticated_user_id' => $user->id,
            'last_authenticated_user_name' => $user->name ?: $user->email ?: 'unknown',
            'last_authenticated_employee_id' => $user->employee_id,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * Audit ditulis sebelum logout agar jejak session timeout tetap punya actor yang jelas.
     */
    private function auditTimeout(Request $request, int $lastActivity, int $timedOutAt): void
    {
        $user = Auth::user();
        $userId = $request->session()->get('last_authenticated_user_id');
        $userName = $request->session()->get('last_authenticated_user_name');

        if ((! is_string($userId) || $userId === '') && $user instanceof User) {
            $userId = $user->id;
        }

        if ((! is_string($userName) || $userName === '') && $user instanceof User) {
            $userName = $user->name ?: $user->email ?: 'unknown';
        }

        if (! is_string($userId) || $userId === '') {
            Log::warning('Session timeout tidak dapat diaudit karena identitas user tidak tersedia.', [
                'path' => $request->path(),
                'last_activity_at' => $lastActivity,
                'timed_out_at' => $timedOutAt,
            ]);

            return;
        }

        AuditService::logAs(
            $userId,
            is_string($userName) && $userName !== '' ? $userName : 'unknown',
            'SESSION_TIMEOUT',
            'User',
            $userId,
            ['last_activity_at' => $lastActivity],
            ['timed_out_at' => $timedOutAt],
            $request,
        );
    }
}
