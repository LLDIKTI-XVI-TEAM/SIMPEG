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

    /**
     * Memproses callback Keycloak.
     *
     * Kontrak K-MTG-02 / Issue #6: Keycloak hanya membuktikan identitas; RBAC tetap
     * sumber internal SIMPEG. Callback tidak pernah memberi elevated role dari
     * email/claim SSO — role kosong diinisialisasi ke default Pegawai, kecuali akun
     * pertama sistem yang di-bootstrap menjadi Super Admin (keputusan stakeholder).
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
                $this->auditMappingRejected(null, 'employee_inactive', $matchedEmail, $employee->id, $request);

                return view('auth.unregistered', [
                    'message' => 'Akun pegawai tidak aktif.',
                ]);
            }

            // Kontrak Issue #6: resolver user deterministik keycloak_id → employee_id →
            // controlled email fallback. User existing milik pegawai yang sama wajib dipakai
            // ulang meskipun email internalnya berbeda dari email SSO terverifikasi.
            $userByEmployee = User::where('employee_id', $employee->id)->first();
            $userByEmail = User::whereRaw('lower(email) = ?', [$matchedEmail])->first();

            if ($userByEmployee && $userByEmail && $userByEmployee->isNot($userByEmail)) {
                // Dua user berbeda menunjuk identitas yang sama → fail-closed, jangan menebak.
                $this->auditMappingRejected($userByEmployee, 'identity_conflict', $matchedEmail, $employee->id, $request);

                return view('auth.unregistered', [
                    'message' => 'Konflik identitas akun SIMPEG terdeteksi.',
                ]);
            }

            $user = $userByEmployee ?? $userByEmail;

            if ($user && $user->employee_id !== null && $user->employee_id !== $employee->id) {
                $this->auditMappingRejected($user, 'employee_mismatch', $matchedEmail, $employee->id, $request);

                return view('auth.unregistered', [
                    'message' => 'Akun SIMPEG sudah terhubung ke pegawai lain.',
                ]);
            }

            if ($user && $user->employee_id === null && $user->role !== 'pegawai') {
                $this->auditMappingRejected($user, 'manual_binding_required', $matchedEmail, $employee->id, $request);

                return view('auth.unregistered', [
                    'message' => 'Akun SIMPEG perlu ditautkan manual oleh admin.',
                ]);
            }

            if ($user && $user->keycloak_id !== null && $user->keycloak_id !== $keycloakId) {
                $this->auditMappingRejected($user, 'sso_subject_conflict', $matchedEmail, $employee->id, $request);

                return view('auth.unregistered', [
                    'message' => 'Akun SIMPEG sudah terhubung ke SSO lain.',
                ]);
            }

            if ($user) {
                // Reuse user existing: email internal TIDAK ditimpa agar identitas kanonis
                // aplikasi tetap; yang diikat hanyalah subject Keycloak dan metadata login.
                $user->fill([
                    'name' => $keycloakUser->getName() ?: $username ?: $employee->nama_lengkap,
                    'keycloak_id' => $keycloakId,
                    'employee_id' => $employee->id,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);
            } else {
                $user = new User(['email' => $matchedEmail]);
                $user->fill([
                    'name' => $keycloakUser->getName() ?: $username ?: $employee->nama_lengkap,
                    'keycloak_id' => $keycloakId,
                    'employee_id' => $employee->id,
                    'email_verified_at' => now(),
                ]);
            }

            // Guard benturan juga di jalur user baru: username hanya diambil jika belum
            // dipakai user lain; identitas kanonis tetap keycloak_id (subject Keycloak).
            if ($this->usernameIsAvailable($user, $username)) {
                $user->keycloak_username = $username;
            }

            if (! $user->exists) {
                // SSO hanya membuktikan identitas: role internal akun baru selalu default
                // Pegawai; akun pertama sistem diberi super_admin sebagai bootstrap agar
                // dapat dikonfigurasi (keputusan stakeholder, bukan otorisasi dari email).
                $user->role = User::query()->exists() ? 'pegawai' : 'super_admin';
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

        // Role internal kosong (null atau string kosong) pada pegawai valid selalu
        // diinisialisasi ke default internal Pegawai — SSO tidak pernah menjadi sumber
        // elevated role (K-MTG-02); role yang sudah ditetapkan tidak pernah dioverwrite.
        // Inisialisasi hanya untuk pegawai yang masih aktif: pegawai yang sudah dinonaktifkan
        // (soft-delete maupun status referensi Non-Aktif/Pensiun/Mutasi) tidak layak menerima
        // role baru, agar akses yang dicabut lewat deaktivasi tidak pulih.
        if ($user->employee_id !== null
            && is_string($user->employee_id)
            && ! $this->employeeIsInactive($user->employee_id)
            && in_array($user->role, [null, ''], true)) {
            $user->role = 'pegawai';
        }

        // Binding keycloak_id pertama dan inisialisasi role adalah mutasi penting: keduanya
        // disimpan bersama jejak auditnya dalam satu transaksi agar selalu punya evidence,
        // dan kegagalan audit membatalkan perubahan (fail-closed).
        $rawPreviousRole = $user->getRawOriginal('role');
        $previousRole = is_string($rawPreviousRole) ? $rawPreviousRole : null;
        $roleInitialized = in_array($previousRole, [null, ''], true)
            && $user->role !== null
            && $user->role !== '';

        $rawPreviousSubject = $user->getRawOriginal('keycloak_id');
        $firstBinding = in_array($rawPreviousSubject, [null, ''], true)
            && is_string($user->keycloak_id)
            && $user->keycloak_id !== '';

        DB::transaction(function () use ($user, $previousRole, $roleInitialized, $firstBinding, $request): void {
            $user->save();

            if ($firstBinding) {
                $this->auditIdentityBinding($user, $request);
            }

            if ($roleInitialized) {
                $this->auditRoleInitialization($user, $previousRole, $request);
            }
        });

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
                // Kelayakan aktif kanonis berasal dari ref_status_pegawai.kelompok
                // (diperiksa employeeIsInactive). Filter deleted_at hanya pelengkap
                // sementara Employee masih memakai SoftDeletes (selaras PR #19).
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
     * Mencatat inisialisasi role internal saat login SSO (role kosong → role baru).
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
            ['role' => $user->role, 'employee_id' => $user->employee_id, 'source' => 'sso_bootstrap'],
            $request,
        );
    }

    /**
     * Mencatat pengikatan pertama subject Keycloak (keycloak_id) pada user SIMPEG.
     *
     * Fail-closed: kegagalan menulis audit membatalkan binding. Payload hanya memuat
     * identitas kanonis (subject, employee) tanpa payload/token mentah Keycloak.
     */
    private function auditIdentityBinding(User $user, Request $request): void
    {
        AuditService::logAsOrFail(
            $user->id,
            $user->name,
            'SSO_BINDING',
            'User',
            $user->id,
            ['keycloak_id' => null],
            ['keycloak_id' => $user->keycloak_id, 'employee_id' => $user->employee_id, 'source' => 'sso_callback'],
            $request,
        );
    }

    /**
     * Mencatat penolakan mapping identitas SSO (pegawai nonaktif / konflik identitas)
     * sebagai evidence keamanan tanpa menyimpan payload mentah Keycloak.
     */
    private function auditMappingRejected(?User $user, string $reason, ?string $email, ?string $employeeId, Request $request): void
    {
        AuditService::logAsOrFail(
            $user?->id ?? 'system',
            $user?->name ?? 'SSO Callback',
            'SSO_MAPPING_REJECTED',
            'User',
            $user?->id,
            null,
            ['reason' => $reason, 'email' => $email, 'employee_id' => $employeeId, 'source' => 'sso_callback'],
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
