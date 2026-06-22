<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class KeycloakAuthController extends Controller
{
    public function redirectToKeycloak(): RedirectResponse
    {
        return Socialite::driver('keycloak')->redirect();
    }

    public function handleCallback(): RedirectResponse|View
    {
        try {
            $keycloakUser = Socialite::driver('keycloak')->user();
        } catch (Throwable) {
            return redirect()
                ->route('login')
                ->with('auth_error', 'Login Keycloak gagal. Silakan coba kembali.');
        }

        $keycloakId = $keycloakUser->getId();
        $username = $keycloakUser->getNickname();
        $email = $keycloakUser->getEmail() ?: ($username ? $username.'@keycloak.local' : null);

        if (! $email || ! $keycloakId) {
            return view('auth.unregistered', [
                'message' => 'Akun Keycloak belum memiliki ID atau username yang bisa dipakai SIMPEG.',
            ]);
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->fill([
            'name' => $keycloakUser->getName() ?: $username ?: $email,
            'keycloak_id' => $keycloakId,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        if (! $user->exists) {
            $user->password = Str::random(48);
        }

        $user->save();

        Auth::login($user);
        request()->session()->regenerate();
        session(['active_role' => $user->role ?? 'Pegawai']);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(): RedirectResponse
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        $redirectUri = config('app.url');
        $clientId = config('services.keycloak.client_id');

        return redirect(Socialite::driver('keycloak')->getLogoutUrl($redirectUri, $clientId));
    }
}
