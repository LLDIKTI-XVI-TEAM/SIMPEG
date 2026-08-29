<?php

namespace App\Actions\Auth;

use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;
use App\Support\IdentifierMasker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * Pesan pengguna per alasan penolakan mapping (tidak membocorkan detail internal).
     *
     * @var array<string, string>
     */
    private const REJECT_MESSAGES = [
        'identity_conflict' => 'Konflik identitas akun SIMPEG terdeteksi.',
        'employee_mismatch' => 'Akun SIMPEG sudah terhubung ke pegawai lain.',
        'manual_binding_required' => 'Akun SIMPEG perlu ditautkan manual oleh admin.',
        'sso_subject_conflict' => 'Akun SIMPEG sudah terhubung ke SSO lain.',
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
            // Email internal user yang sudah terikat tidak pernah diverifikasi ulang oleh
            // Keycloak pada jalur ini — status verifikasi existing dipertahankan apa adanya.
            return $this->loginMappedUser($existingUser, $keycloakId, $username, $keycloakUser->getName(), $request, markEmailVerified: false);
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

            if ($employees->isEmpty()) {
                // Kontrak Issue #6: respons terkontrol + audit untuk nol kecocokan.
                $this->auditMappingRejected(null, 'employee_match_not_found', $matchedEmail, null, $request);

                return view('auth.unregistered', [
                    'message' => 'Akun Keycloak belum terdaftar sebagai pegawai SIMPEG.',
                ]);
            }

            if ($employees->count() > 1) {
                // Lebih dari satu pegawai cocok → ambigu, fail-closed + audit.
                $this->auditMappingRejected(null, 'employee_match_ambiguous', $matchedEmail, null, $request);

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
            try {
                $user = $this->resolveUserForEmployee($employee, $keycloakId, $username, $keycloakUser->getName(), $matchedEmail, $request);
            } catch (SsoIdentityRejected $rejected) {
                return view('auth.unregistered', ['message' => $rejected->userMessage]);
            }

            try {
                return $this->loginMappedUser($user, $keycloakId, $username, $keycloakUser->getName(), $request, markEmailVerified: $this->emailMatchesVerifiedClaim($user, $matchedEmail));
            } catch (UniqueConstraintViolationException) {
                // Database adalah authority terakhir: benturan unik berarti callback
                // paralel sudah membuat/mengikat user setelah resolusi kita. Re-resolve
                // dengan state terbaru; bila state terbaru tetap inkonsisten, resolver
                // melempar SsoIdentityRejected (fail-closed) — tidak pernah menebak.
                try {
                    $user = $this->resolveUserForEmployee($employee, $keycloakId, $username, $keycloakUser->getName(), $matchedEmail, $request);
                } catch (SsoIdentityRejected $rejected) {
                    return view('auth.unregistered', ['message' => $rejected->userMessage]);
                }

                return $this->loginMappedUser($user, $keycloakId, $username, $keycloakUser->getName(), $request, markEmailVerified: $this->emailMatchesVerifiedClaim($user, $matchedEmail));
            }
        }

        // Akun tanpa email terverifikasi tidak memiliki jalur khusus: seluruh login
        // harus melalui identitas Keycloak asli (akun demo/dev whitelist dihapus).
        return view('auth.unregistered', [
            'message' => 'Akun Keycloak belum terdaftar di SIMPEG.',
        ]);
    }

    /**
     * Meresolusi user untuk pegawai yang cocok dan menyiapkan binding identitas.
     *
     * Kontrak Issue #6: resolver deterministik keycloak_id → employee_id → controlled
     * email fallback, dengan database sebagai authority terakhir terhadap callback
     * paralel. Seluruh lookup berjalan di dalam satu transaksi yang mengunci baris
     * employee sehingga dua callback bersamaan untuk pegawai yang sama terserialisasi;
     * re-check identitas setelah lock menghilangkan TOCTOU. Penolakan guard tetap
     * diaudit SETELAH transaksi commit agar evidence tidak ikut ter-rollback.
     *
     * @throws SsoIdentityRejected bila state terkini inkonsisten (sudah ter-audit).
     */
    private function resolveUserForEmployee(Employee $employee, string $keycloakId, ?string $username, ?string $name, string $matchedEmail, Request $request): User
    {
        $state = DB::transaction(function () use ($employee, $keycloakId, $username, $name, $matchedEmail): array {
            // Serialisasi callback paralel untuk pegawai yang sama: kunci baris employee
            // sebelum re-check identitas (TOCTOU guard pada boundary database).
            Employee::withTrashed()->whereKey($employee->id)->lockForUpdate()->first();

            $userByEmployee = User::where('employee_id', $employee->id)->lockForUpdate()->first();
            $userByEmail = User::whereRaw('lower(email) = ?', [$matchedEmail])->lockForUpdate()->first();

            if ($userByEmployee && $userByEmail && $userByEmployee->isNot($userByEmail)) {
                // Dua user berbeda menunjuk identitas yang sama → fail-closed, jangan menebak.
                return ['reject' => ['reason' => 'identity_conflict', 'user' => $userByEmployee]];
            }

            $user = $userByEmployee ?? $userByEmail;

            if ($user && $user->employee_id !== null && $user->employee_id !== $employee->id) {
                return ['reject' => ['reason' => 'employee_mismatch', 'user' => $user]];
            }

            if ($user && $user->employee_id === null && $user->role !== 'pegawai') {
                return ['reject' => ['reason' => 'manual_binding_required', 'user' => $user]];
            }

            if ($user && $user->keycloak_id !== null && $user->keycloak_id !== $keycloakId) {
                return ['reject' => ['reason' => 'sso_subject_conflict', 'user' => $user]];
            }

            if ($user) {
                // Reuse user existing: email internal TIDAK ditimpa agar identitas kanonis
                // aplikasi tetap; yang diikat hanyalah subject Keycloak dan metadata login.
                // Status verifikasi email JUGA tidak disentuh di sini: verifikasi hanya
                // sah untuk email yang benar-benar diverifikasi IdP (diputuskan pemanggil).
                $user->fill([
                    'name' => $name ?: $username ?: $employee->nama_lengkap,
                    'keycloak_id' => $keycloakId,
                    'employee_id' => $employee->id,
                ]);
            } else {
                $user = new User(['email' => $matchedEmail]);
                $user->fill([
                    'name' => $name ?: $username ?: $employee->nama_lengkap,
                    'keycloak_id' => $keycloakId,
                    'employee_id' => $employee->id,
                    'email_verified_at' => now(),
                ]);

                // SSO hanya membuktikan identitas: role internal akun baru selalu default
                // Pegawai; akun pertama sistem diberi super_admin sebagai bootstrap agar
                // dapat dikonfigurasi (keputusan stakeholder, bukan otorisasi dari email).
                $user->role = User::query()->exists() ? 'pegawai' : 'super_admin';
                $user->password = Str::random(48);
            }

            // Guard benturan sebagai fast-path saja: pemeriksaan ulang di boundary
            // database tetap dilakukan lewat unique constraint saat save (loginMappedUser).
            if ($this->usernameIsAvailable($user, $username)) {
                $user->keycloak_username = $username;
            }

            return ['user' => $user];
        });

        if (isset($state['reject'])) {
            $this->auditMappingRejected($state['reject']['user'], $state['reject']['reason'], $matchedEmail, $employee->id, $request);

            throw new SsoIdentityRejected($state['reject']['reason'], self::REJECT_MESSAGES[$state['reject']['reason']]);
        }

        return $state['user'];
    }

    private function loginMappedUser(User $user, string $keycloakId, ?string $username, ?string $name, Request $request, bool $markEmailVerified = false): RedirectResponse|View
    {
        $user->fill([
            'name' => $name ?: $user->name,
            'keycloak_id' => $keycloakId,
        ]);

        // email_verified_at hanya ditandai bila email kanonis user memang sama dengan
        // email SSO yang diverifikasi IdP (atau user baru yang emailnya berasal dari
        // klaim terverifikasi itu). Email internal yang tidak pernah diverifikasi
        // Keycloak tidak boleh ikut tercatat terverifikasi; nilai existing tidak
        // pernah dicabut.
        if ($markEmailVerified && $user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

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
            // Save (termasuk retry username) dibungkus savepoint via transaction nested:
            // di PostgreSQL statement yang gagal men-abort transaksi, jadi rollback ke
            // savepoint diperlukan sebelum save ulang. Audit tetap dieksekusi setelahnya.
            try {
                DB::transaction(function () use ($user): void {
                    $user->save();
                });
            } catch (UniqueConstraintViolationException $e) {
                // Authority terakhir adalah constraint DB: satu-satunya benturan yang
                // mungkin di titik ini adalah keycloak_username yang baru diklaim bersamaan
                // (identitas kanonis sudah divalidasi resolver + kunci transaksinya).
                // Ulangi save tanpa username tersebut; identitas login tetap keycloak_id.
                // Benturan lain diteruskan agar execute() re-resolve / fail-closed.
                if (! $this->usernameWasJustClaimed($user)) {
                    throw $e;
                }

                $user->keycloak_username = $user->getRawOriginal('keycloak_username');

                DB::transaction(function () use ($user): void {
                    $user->save();
                });
            }

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
     * identitas kanonis (subject, employee) tanpa payload/token mentah Keycloak, dan
     * subject tersamarkan (keycloak_id_masked) — audit bersifat append-only sehingga
     * identifier eksternal utuh tidak boleh tersimpan permanen (konsisten dengan
     * jalur pemetaan admin di UpdateUserMappingAction).
     */
    private function auditIdentityBinding(User $user, Request $request): void
    {
        AuditService::logAsOrFail(
            $user->id,
            $user->name,
            'SSO_BINDING',
            'User',
            $user->id,
            ['keycloak_id_masked' => null],
            [
                'keycloak_id_masked' => IdentifierMasker::mask($user->keycloak_id),
                'employee_id' => $user->employee_id,
                'source' => 'sso_callback',
            ],
            $request,
        );
    }

    /**
     * Mencatat penolakan mapping identitas SSO (pegawai nonaktif / konflik identitas)
     * sebagai evidence keamanan tanpa menyimpan payload mentah Keycloak.
     *
     * Aktornya SELALU sistem: pemilik akun yang tersentuh belum terautentikasi dan
     * mungkin justru korban percobaan pengikatan — atribusi ke user tersebut akan
     * salah menunjuk pelaku. User terkait tetap tertelusuri lewat auditable_id.
     */
    private function auditMappingRejected(?User $user, string $reason, ?string $email, ?string $employeeId, Request $request): void
    {
        AuditService::logAsOrFail(
            'system',
            'SSO Callback',
            'SSO_MAPPING_REJECTED',
            'User',
            $user?->id,
            null,
            ['reason' => $reason, 'email' => $email, 'employee_id' => $employeeId, 'source' => 'sso_callback'],
            $request,
        );
    }

    /**
     * True bila email kanonis user sama dengan email SSO terverifikasi yang sedang
     * dipetakan, sehingga menandai email terverifikasi adalah pernyataan yang benar.
     */
    private function emailMatchesVerifiedClaim(User $user, string $matchedEmail): bool
    {
        return strtolower((string) $user->email) === $matchedEmail;
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
     * True bila keycloak_username pada model ini adalah klaim BARU (sebelumnya null/kosong),
     * sehingga unique violation saat save layak dicoba ulang tanpa username tersebut.
     */
    private function usernameWasJustClaimed(User $user): bool
    {
        return is_string($user->keycloak_username)
            && $user->keycloak_username !== ''
            && in_array($user->getRawOriginal('keycloak_username'), [null, ''], true);
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
