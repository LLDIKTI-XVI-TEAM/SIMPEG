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

            // Pegawai nonaktif tidak boleh mendapat akun baru ber-privilege — cek sebelum role assignment
            // (matchedEmployees hanya filter deleted_at; kelompok Nonaktif/Pensiun/Mutasi harus ditolak di sini).
            if ($this->employeeIsInactive($employee->id)) {
                return view('auth.unregistered', [
                    'message' => 'Akun pegawai tidak aktif.',
                ]);
            }

            // Fail-closed untuk mapping invalid: email tercantum di role_mapping tetapi nilainya
            // di luar allowlist tidak boleh jatuh ke fallback pegawai/super_admin.
            if ($this->isInvalidRoleMapping($matchedEmail)) {
                return view('auth.unregistered', [
                    'message' => 'Konfigurasi role mapping tidak valid.',
                ]);
            }

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
                // Invalid mapping sudah ditolak di atas, jadi fallback hanya untuk missing mapping.
                $user->role = $this->mappedRoleForEmail($matchedEmail)
                    ?? (User::query()->exists() ? 'pegawai' : 'super_admin');
                $user->password = Str::random(48);
            }

            return $this->loginMappedUser($user, $keycloakId, $username, $keycloakUser->getName(), $request);
        }

        // Akun tanpa email terverifikasi tidak memiliki jalur khusus: seluruh login
        // harus melalui identitas Keycloak asli (akun demo/dev whitelist dihapus).
        return view('auth.unregistered', [
            'message' => 'Akun Keycloak belum terdaftar di SIMPEG.',
        ]);
    }

    private function loginMappedUser(User $user, string $keycloakId, ?string $username, ?string $name, Request $request): RedirectResponse|View
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

        // Fail-closed untuk mapping invalid: email tercantum di role_mapping tetapi nilainya
        // di luar allowlist tidak boleh jatuh ke fallback pegawai.
        if ($user->employee_id !== null
            && is_string($user->employee_id)
            && in_array($user->role, [null, ''], true)
            && $this->isInvalidRoleMapping($user->email)) {
            return view('auth.unregistered', [
                'message' => 'Konfigurasi role mapping tidak valid.',
            ]);
        }

        // Role internal kosong (null atau string kosong) pada mapping pegawai valid
        // diinisialisasi mengikuti pemetaan email SSO bila tersedia, selain itu sebagai
        // Pegawai; role yang sudah ditetapkan tidak pernah dioverwrite.
        // Inisialisasi hanya untuk pegawai yang masih aktif: pegawai yang sudah dinonaktifkan
        // (soft-delete maupun status referensi Non-Aktif/Pensiun/Mutasi) tidak layak menerima
        // role baru, agar akses yang dicabut lewat deaktivasi tidak pulih.
        if ($user->employee_id !== null
            && is_string($user->employee_id)
            && ! $this->employeeIsInactive($user->employee_id)
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
     * Apakah pegawai terpeta sudah tidak aktif. Pegawai nonaktif tidak berhak
     * atas inisialisasi role baru lewat SSO.
     *
     * Pemeriksaan mencakup dua skenario: (1) soft-delete via deleted_at, dan
     * (2) status referensi (kelompok) bukan aktif — contoh Pensiun, Mutasi, Nonaktif
     * yang belum di-soft-delete. Fail-closed: pegawai tanpa status dianggap nonaktif.
     */
    private function employeeIsInactive(string $employeeId): bool
    {
        $employee = Employee::withTrashed()->with('statusPegawai')->whereKey($employeeId)->first();

        if (! $employee) {
            return true; // fail-closed
        }

        if ($employee->trashed()) {
            return true;
        }

        // Kelompok status pegawai adalah single source of truth untuk aktif/nonaktif.
        $kelompok = strtolower((string) ($employee->statusPegawai?->kelompok ?? ''));

        return ! in_array($kelompok, ['aktif', 'aktif/khusus'], true);
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

        // Normalisasi key case-insensitive agar pemetaan dengan kapitalisasi
        // berbeda (mis. 'Admin@Example.com') tetap terurai ke role terpetakan,
        // konsisten dengan isInvalidRoleMapping().
        $normalizedMap = array_change_key_case($roleMapping, CASE_LOWER);

        $mapped = $normalizedMap[strtolower(trim($email))] ?? null;

        if (! is_string($mapped) || ! in_array($mapped, self::ALLOWED_INTERNAL_ROLES, true)) {
            return null;
        }

        return $mapped;
    }

    /**
     * True bila email tercantum di role_mapping tetapi nilainya di luar allowlist.
     * Bedakan dari missing mapping (tidak tercantum) yang masih boleh fallback ke pegawai/super_admin.
     */
    private function isInvalidRoleMapping(?string $email): bool
    {
        if (! is_string($email) || trim($email) === '') {
            return false;
        }

        $roleMapping = (array) config('services.keycloak.role_mapping', []);
        // Normalisasi key case-insensitive agar typo kapitalisasi tetap terdeteksi.
        $normalizedMap = array_change_key_case($roleMapping, CASE_LOWER);
        $normalizedEmail = strtolower(trim($email));

        if (! array_key_exists($normalizedEmail, $normalizedMap)) {
            return false;
        }

        $mapped = $normalizedMap[$normalizedEmail];

        return ! is_string($mapped) || ! in_array($mapped, self::ALLOWED_INTERNAL_ROLES, true);
    }

    /**
     * True bila keycloak_username boleh disimpan pada user ini: kosong atau belum
     * dipakai user lain. Constraint unik users_keycloak_username_unique (PostgreSQL
     * case-sensitive) tidak boleh menggagalkan login; selalu periksa DB karena user
     * lain bisa memegang variasi kapitalisasi berbeda dari username yang sama.
     */
    private function usernameIsAvailable(User $user, ?string $username): bool
    {
        if (! is_string($username) || trim($username) === '') {
            return false;
        }

        return ! User::query()
            ->whereKeyNot($user->getKey())
            ->whereRaw('lower(keycloak_username) = ?', [strtolower(trim($username))])
            ->exists();
    }
}
