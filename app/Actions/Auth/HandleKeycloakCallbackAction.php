<?php

namespace App\Actions\Auth;

use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class HandleKeycloakCallbackAction
{
    private const ALLOWED_EMPLOYEE_MATCH_FIELDS = [
        'email',
    ];

    /** Role internal SIMPEG yang sah sebagai target pemetaan email SSO. */
    private const ALLOWED_INTERNAL_ROLES = [
        'super_admin',
        'admin_kepegawaian',
        'pimpinan',
        'kepala_bagian',
        'pegawai',
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
                'employee_id' => $employee->id,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            // Guard benturan juga di jalur user baru: username hanya diambil jika belum
            // dipakai user lain; identitas kanonis tetap keycloak_id (subject Keycloak).
            if ($this->usernameIsAvailable($user, $username)) {
                $user->keycloak_username = $username;
            }

            if (! $user->exists) {
                // Mapping pegawai valid + role internal belum ada → role mengikuti pemetaan
                // email SSO bila tersedia; tanpa pemetaan, default SSO Pegawai berperan
                // sebagai Pegawai; akun pertama sistem diberi akses super_admin agar dapat dikonfigurasi.
                $user->role = $this->mappedRoleForEmail($matchedEmail)
                    ?? (User::query()->exists() ? 'pegawai' : 'super_admin');
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
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        // keycloak_username disimpan hanya jika belum dipakai user lain; identitas kanonis
        // login adalah keycloak_id (subject), jadi benturan username tidak boleh menggagalkan login.
        $claimedUsername = $username ?: $user->keycloak_username;
        if ($this->usernameIsAvailable($user, $claimedUsername)) {
            $user->keycloak_username = $claimedUsername;
        }

        // Role internal kosong (null atau string kosong) pada mapping pegawai valid
        // diinisialisasi mengikuti pemetaan email SSO bila tersedia, selain itu sebagai
        // Pegawai; role yang sudah ditetapkan tidak pernah dioverwrite.
        // Inisialisasi hanya untuk pegawai yang masih aktif: pegawai yang sudah dinonaktifkan
        // (soft-delete) tidak layak menerima role baru, agar akses yang dicabut lewat deaktivasi
        // tidak pulih hanya karena role account lama masih kosong.
        if ($user->employee_id !== null
            && is_string($user->employee_id)
            && ! $this->employeeIsSoftDeleted($user->employee_id)
            && in_array($user->role, [null, ''], true)) {
            $user->role = $this->mappedRoleForEmail($user->email) ?? 'pegawai';
        }

        // Inisialisasi role adalah mutasi penting: disimpan bersama jejak auditnya dalam satu
        // transaksi (old role null/kosong → role baru) agar perubahan mapping/role selalu punya evidence.
        $rawPreviousRole = $user->getRawOriginal('role');
        $previousRole = is_string($rawPreviousRole) ? $rawPreviousRole : null;
        $roleInitialized = in_array($previousRole, [null, ''], true)
            && $user->role !== null
            && $user->role !== '';

        if ($roleInitialized) {
            DB::transaction(function () use ($user, $previousRole, $request): void {
                $user->save();
                $this->auditRoleInitialization($user, $previousRole, $request);
            });
        } else {
            $user->save();
        }

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
                    // Kolom email legacy (tanpa index unik) dicocokkan pada pegawai aktif;
                    // pegawai nonaktif hanya memegang email_pribadi kanonisnya.
                    ->orWhereRaw('lower(email) = ?', [$matchedEmail]);
            })
                // Permukaan autentikasi hanya memetakan pegawai aktif; pegawai yang sudah
                // di-soft-delete tidak boleh menjadi pintu masuk akun SSO baru.
                ->whereNull('deleted_at')
                ->limit(2)
                ->get();
        }

        return Employee::whereRaw('lower('.$employeeField.') = ?', [$matchedEmail])
            ->whereNull('deleted_at')
            ->limit(2)
            ->get();
    }

    /**
     * Mencatat inisialisasi role internal hasil mapping SSO (role kosong → role baru).
     *
     * Fail-closed: kegagalan menulis audit membatalkan perubahan role. Payload memuat old/new
     * role, pegawai yang dipetakan, dan sumber perubahan yang aman (tanpa claim mentah).
     */
    private function auditRoleInitialization(User $user, ?string $previousRole, Request $request): void
    {
        AuditService::logAsOrFail(
            $user->id,
            $user->name,
            'UPDATE',
            'User',
            $user->id,
            ['role' => $previousRole],
            ['role' => $user->role, 'employee_id' => $user->employee_id, 'source' => 'sso_mapping'],
            $request,
        );
    }

    /**
     * Apakah pegawai terpeta sudah dinonaktifkan (soft-delete). Pegawai nonaktif tidak berhak
     * atas inisialisasi role baru lewat SSO.
     */
    private function employeeIsSoftDeleted(string $employeeId): bool
    {
        return (bool) Employee::withTrashed()->whereKey($employeeId)->value('deleted_at');
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

    /**
     * Role internal yang ditetapkan untuk email SSO tertentu lewat config role_mapping.
     *
     * Hanya dipakai pada saat bootstrap (user baru / role internal masih kosong); role yang
     * sudah terisi tidak pernah dioverwrite. Role di luar allowlist ditolak fail-closed.
     */
    private function mappedRoleForEmail(?string $email): ?string
    {
        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        $roleMapping = (array) config('services.keycloak.role_mapping', []);

        $mapped = $roleMapping[strtolower(trim($email))] ?? null;

        if (! is_string($mapped) || ! in_array($mapped, self::ALLOWED_INTERNAL_ROLES, true)) {
            return null;
        }

        return $mapped;
    }

    /**
     * True bila keycloak_username boleh disimpan pada user ini: kosong, sudah miliknya,
     * atau belum dipakai user lain. Constraint unik users_keycloak_username_unique
     * tidak boleh menggagalkan login karena identitas kanonis adalah keycloak_id.
     */
    private function usernameIsAvailable(User $user, ?string $username): bool
    {
        if (! is_string($username) || trim($username) === '') {
            return false;
        }

        if ($user->keycloak_username !== null
            && strtolower(trim($user->keycloak_username)) === strtolower(trim($username))) {
            return true;
        }

        return ! User::query()
            ->whereKeyNot($user->getKey())
            ->whereRaw('lower(keycloak_username) = ?', [strtolower(trim($username))])
            ->exists();
    }
}
