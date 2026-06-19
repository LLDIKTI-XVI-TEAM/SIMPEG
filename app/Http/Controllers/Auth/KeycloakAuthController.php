<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class KeycloakAuthController extends Controller
{
    private const ROLE_MAP = [
        'super_admin' => 'super_admin',
        'superadmin' => 'super_admin',
        'admin_kepegawaian' => 'admin_kepegawaian',
        'admin-kepegawaian' => 'admin_kepegawaian',
        'pimpinan' => 'pimpinan',
        'atasan_langsung' => 'atasan_langsung',
        'atasan-langsung' => 'atasan_langsung',
        'pegawai' => 'pegawai',
    ];

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

        $role = $this->localRoleFromKeycloak($keycloakUser);

        if ($role !== null) {
            $user->role = $role;
        }

        if (! $user->exists) {
            $user->password = Str::random(48);
        }

        $user->save();

        Auth::login($user);
        request()->session()->regenerate();

        AuditService::logAs($user->id, $user->name, 'LOGIN', 'User', $user->id, null, null, request());

        return redirect()->intended(route('dashboard'));
    }

    public function logout(): RedirectResponse
    {
        $user = Auth::user();

        AuditService::logAs($user?->id, $user?->name ?? 'unknown', 'LOGOUT', 'User', $user?->id, null, null, request());

        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        $redirectUri = config('app.url');
        $clientId = config('services.keycloak.client_id');

        return redirect(Socialite::driver('keycloak')->getLogoutUrl($redirectUri, $clientId));
    }

    private function localRoleFromKeycloak(object $keycloakUser): ?string
    {
        foreach ($this->keycloakRoles($keycloakUser) as $role) {
            $key = Str::of((string) $role)->trim()->lower()->replace(' ', '_')->toString();

            if (isset(self::ROLE_MAP[$key])) {
                return self::ROLE_MAP[$key];
            }
        }

        return null;
    }

    private function keycloakRoles(object $keycloakUser): array
    {
        $claims = property_exists($keycloakUser, 'user') && is_array($keycloakUser->user)
            ? $keycloakUser->user
            : [];

        $roles = data_get($claims, 'realm_access.roles', []);

        foreach (data_get($claims, 'resource_access', []) as $resource) {
            $roles = array_merge($roles, $resource['roles'] ?? []);
        }

        return array_values(array_unique(array_filter($roles)));
    }
}
