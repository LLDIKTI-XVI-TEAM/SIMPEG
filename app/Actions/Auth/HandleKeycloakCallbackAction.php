<?php

namespace App\Actions\Auth;

use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class HandleKeycloakCallbackAction
{
    private const ALLOWED_EMPLOYEE_MATCH_FIELDS = [
        'email',
    ];

    /**
     * Memproses callback Keycloak tanpa memakai role claim Keycloak sebagai sumber RBAC SIMPEG.
     */
    public function execute(Request $request): RedirectResponse|View
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
            return $this->loginMappedUser($existingUser, $keycloakId, $username, $keycloakUser->getName(), $request);
        }

        // Pegawai asli wajib cocok ke data employees; akun tanpa email hanya boleh lewat whitelist user lokal.
        $employeeField = $this->employeeMatchField();

        if (! $employeeField) {
            return view('auth.unregistered', [
                'message' => 'Konfigurasi pencocokan akun SSO belum valid.',
            ]);
        }

        $matchedEmail = $this->verifiedEmailClaim($keycloakUser);

        if ($matchedEmail) {
            $employees = $this->matchedEmployees($employeeField, $matchedEmail);

            if ($employees->count() !== 1) {
                return view('auth.unregistered', [
                    'message' => 'Akun Keycloak belum terdaftar sebagai pegawai SIMPEG.',
                ]);
            }

            $employee = $employees->first();
            $user = User::whereRaw('lower(email) = ?', [$matchedEmail])->first();

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
                // Bootstrap pertama memberi akses super_admin; setelah itu role wajib ditetapkan admin SIMPEG.
                $user->role = User::query()->exists() ? null : 'super_admin';
                $user->password = Str::random(48);
            }

            return $this->loginMappedUser($user, $keycloakId, $username, $keycloakUser->getName(), $request);
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

        return $this->loginMappedUser($devUser, $keycloakId, $username, $keycloakUser->getName(), $request);
    }

    private function loginMappedUser(User $user, string $keycloakId, ?string $username, ?string $name, Request $request): RedirectResponse
    {
        $user->fill([
            'name' => $name ?: $user->name,
            'keycloak_id' => $keycloakId,
            'keycloak_username' => $username ?: $user->keycloak_username,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $user->save();

        Auth::login($user);
        $request->session()->regenerate();

        AuditService::logAs($user->id, $user->name, 'LOGIN', 'User', $user->id, null, null, $request);

        return redirect()->intended(route('dashboard'))->with('login_success', 'Selamat Datang, '.$user->name.'! Anda berhasil masuk ke dalam sistem.');
    }

    private function employeeMatchField(): ?string
    {
        $field = config('services.keycloak.employee_match_field', 'email');

        if (! in_array($field, self::ALLOWED_EMPLOYEE_MATCH_FIELDS, true)) {
            return null;
        }

        return $field;
    }

    /** @return Collection<int, Employee> */
    private function matchedEmployees(string $employeeField, string $matchedEmail): Collection
    {
        if ($employeeField === 'email') {
            return Employee::where(function ($query) use ($matchedEmail): void {
                $query
                    ->whereRaw('lower(email_pribadi) = ?', [$matchedEmail])
                    ->orWhereRaw('lower(email) = ?', [$matchedEmail]);
            })->limit(2)->get();
        }

        return Employee::whereRaw('lower('.$employeeField.') = ?', [$matchedEmail])->limit(2)->get();
    }

    /**
     * Tautan pegawai hanya memakai email Keycloak yang sudah diverifikasi oleh IdP.
     */
    private function verifiedEmailClaim(object $keycloakUser): ?string
    {
        $claims = property_exists($keycloakUser, 'user') && is_array($keycloakUser->user)
            ? $keycloakUser->user
            : [];

        if (data_get($claims, 'email_verified') !== true) {
            return null;
        }

        $value = $keycloakUser->getEmail();

        $value = is_string($value) ? trim(strtolower($value)) : null;

        if ($value === '' || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $value;
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
