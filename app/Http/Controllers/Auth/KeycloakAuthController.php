<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Employee;
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
    private const ALLOWED_EMPLOYEE_MATCH_CLAIMS = [
        'email',
        'preferred_username',
    ];

    private const ALLOWED_EMPLOYEE_MATCH_FIELDS = [
        'email_pribadi',
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

        if (! $keycloakId) {
            return view('auth.unregistered', [
                'message' => 'Akun Keycloak belum memiliki ID yang bisa dipakai SIMPEG.',
            ]);
        }

        // Login berikutnya memakai subject Keycloak yang stabil agar perubahan email tidak memindahkan akun.
        $existingUser = User::where('keycloak_id', $keycloakId)->first();

        if ($existingUser) {
            return $this->loginMappedUser($existingUser, $keycloakId, $username, $keycloakUser->getName());
        }

        // Pegawai asli wajib cocok ke data employees; akun tanpa email hanya boleh lewat whitelist user lokal.
        $matchedEmail = $this->claimValue($keycloakUser);

        if ($matchedEmail) {
            $employeeField = $this->employeeMatchField();

            if (! $employeeField) {
                return view('auth.unregistered', [
                    'message' => 'Konfigurasi pencocokan akun SSO belum valid.',
                ]);
            }

            $employees = Employee::whereRaw('lower('.$employeeField.') = ?', [$matchedEmail])->limit(2)->get();

            if ($employees->count() !== 1) {
                return view('auth.unregistered', [
                    'message' => 'Akun Keycloak belum terdaftar sebagai pegawai SIMPEG.',
                ]);
            }

            $employee = $employees->first();

            $user = User::where('email', $matchedEmail)->first();

            if ($user && $user->employee_id !== null && $user->employee_id !== $employee->id) {
                return view('auth.unregistered', [
                    'message' => 'Akun SIMPEG sudah terhubung ke pegawai lain.',
                ]);
            }

            if ($user && $user->employee_id === null && $user->role !== 'pegawai') {
                return view('auth.unregistered', [
                    'message' => 'Akun SIMPEG perlu ditautkan manual oleh admin.',
                ]);
            }

            if ($user && $user->keycloak_id !== null && $user->keycloak_id !== $keycloakId) {
                return view('auth.unregistered', [
                    'message' => 'Akun SIMPEG sudah terhubung ke SSO lain.',
                ]);
            }

            $user ??= new User(['email' => $matchedEmail]);
            $user->fill([
                'name' => $keycloakUser->getName() ?: $username ?: $employee->nama_lengkap,
                'keycloak_id' => $keycloakId,
                'keycloak_username' => $username,
                'employee_id' => $employee->id,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            if (! $user->exists) {
                // Role awal pegawai berasal dari SIMPEG, bukan claim role Keycloak.
                $user->role = 'pegawai';
                $user->password = Str::random(48);
            }

            return $this->loginMappedUser($user, $keycloakId, $username, $keycloakUser->getName());
        }

        // Akun development seperti demo-klabat harus sudah dibuat di SIMPEG, tidak dibuat otomatis dari Keycloak.
        $devUser = $username && $this->isAllowedDevUsername($username)
            ? User::where('keycloak_username', $username)->first()
            : null;

        if (! $devUser) {
            return view('auth.unregistered', [
                'message' => 'Akun Keycloak belum terdaftar di SIMPEG.',
            ]);
        }

        return $this->loginMappedUser($devUser, $keycloakId, $username, $keycloakUser->getName());
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

    private function loginMappedUser(User $user, string $keycloakId, ?string $username, ?string $name): RedirectResponse
    {
        $user->fill([
            'name' => $name ?: $user->name,
            'keycloak_id' => $keycloakId,
            'keycloak_username' => $username ?: $user->keycloak_username,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $user->save();

        Auth::login($user);
        request()->session()->regenerate();

        AuditService::logAs($user->id, $user->name, 'LOGIN', 'User', $user->id, null, null, request());

        return redirect()->intended(route('dashboard'));
    }

    private function claimValue(object $keycloakUser): ?string
    {
        $claim = config('services.keycloak.employee_match_claim', 'email');

        if (! in_array($claim, self::ALLOWED_EMPLOYEE_MATCH_CLAIMS, true)) {
            return null;
        }

        $claims = property_exists($keycloakUser, 'user') && is_array($keycloakUser->user)
            ? $keycloakUser->user
            : [];

        $value = $claim === 'email'
            ? $keycloakUser->getEmail()
            : data_get($claims, $claim);

        $value = is_string($value) ? trim(strtolower($value)) : null;

        return $value !== '' ? $value : null;
    }

    private function employeeMatchField(): ?string
    {
        $field = config('services.keycloak.employee_match_field', 'email_pribadi');

        if (! in_array($field, self::ALLOWED_EMPLOYEE_MATCH_FIELDS, true)) {
            return null;
        }

        return $field;
    }

    private function isAllowedDevUsername(string $username): bool
    {
        $allowedUsernames = array_map(
            fn (string $value): string => strtolower(trim($value)),
            config('services.keycloak.dev_usernames', []),
        );

        return in_array(strtolower(trim($username)), $allowedUsernames, true);
    }
}
