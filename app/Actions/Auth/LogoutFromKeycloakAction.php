<?php

namespace App\Actions\Auth;

use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class LogoutFromKeycloakAction
{
    /**
     * Mencatat logout sebelum session lokal dihapus, lalu memutus session Keycloak.
     */
    public function execute(Request $request): RedirectResponse
    {
        $user = Auth::user();

        AuditService::logAs($user?->id, $user?->name ?? 'unknown', 'LOGOUT', 'User', $user?->id, null, null, $request);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $redirectUri = config('app.url');
        $clientId = config('services.keycloak.client_id');

        return redirect(Socialite::driver('keycloak')->getLogoutUrl($redirectUri, $clientId));
    }
}
