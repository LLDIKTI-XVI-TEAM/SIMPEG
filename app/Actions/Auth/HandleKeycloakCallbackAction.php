<?php

namespace App\Actions\Auth;

use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
            $verifiedEmailForExisting = $this->verifiedEmailClaim($keycloakUser);

            return $this->loginMappedUser($existingUser, $keycloakId, $username, $keycloakUser->getName(), $request, $verifiedEmailForExisting);
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
                // K-MTG-02 (addendum 15 Agu 2026): mapping pegawai valid + role internal belum
                // diinisialisasi → default SSO Pegawai menginisialisasi role pegawai. Bootstrap
                // pertama tetap super_admin agar sistem dapat dikonfigurasi sebelum ada admin.
                $user->role = User::query()->exists() ? 'pegawai' : 'super_admin';
                $user->password = Str::random(48);
            }

            return $this->loginMappedUser($user, $keycloakId, $username, $keycloakUser->getName(), $request, $matchedEmail);
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

    private function loginMappedUser(User $user, string $keycloakId, ?string $username, ?string $name, Request $request, ?string $verifiedEmail = null): RedirectResponse
    {
        $user->fill([
            'name' => $name ?: $user->name,
            'keycloak_id' => $keycloakId,
            'keycloak_username' => $username ?: $user->keycloak_username,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        // K-MTG-02 (addendum 15 Agu 2026): user lama dari perilaku pra-addendum — role internal
        // kosong padahal mapping pegawai valid — diinisialisasi menjadi pegawai saat login.
        // Role yang sudah ditetapkan admin tidak pernah dioverwrite.
        if ($user->employee_id !== null && $user->role === null) {
            $user->role = 'pegawai';
        }

        $user->save();

        // Sync email Keycloak yang sudah diverifikasi ke kolom email_pribadi pegawai,
        // agar email yang dipakai SSO selalu konsisten dengan data kepegawaian.
        if ($verifiedEmail && $user->employee_id) {
            // Pegawai nonaktif tetap pemilik email_pribadi-nya; temukan juga record yang dihapus
            // agar tidak menyinkronkan email ke pegawai lain saat index unik masih mencadangkannya.
            $employee = Employee::withTrashed()->find($user->employee_id);

            if ($employee
                && strtolower(trim((string) $employee->getRawOriginal('email_pribadi'))) !== $verifiedEmail
                && ! $this->emailIsOwnedByAnotherEmployee($employee, $verifiedEmail)) {
                $previousEmail = (string) $employee->getRawOriginal('email_pribadi');

                try {
                    $employee->email_pribadi = $verifiedEmail;
                    $employee->saveQuietly();

                    // Tindak lanjut review PR #199: mutasi persisted email canonical dicatat ke
                    // audit dengan old/new value + aktor SSO. Tidak menyimpan raw claim/token Keycloak.
                    $this->auditEmailSync($user, $employee, $previousEmail, $verifiedEmail, $request);
                } catch (QueryException $exception) {
                    // Dua callback untuk pegawai berbeda bisa membawa email yang sama secara bersamaan;
                    // keduanya lolos pemeriksaan kepemilikan sebelum salah satu menulis. Penulisan kedua
                    // melanggar index unik case-insensitive employees_email_pribadi_unique.
                    if (! $this->isEmailPribadiConflict($exception)) {
                        throw $exception;
                    }

                    // Keputusan produk (tindak lanjut review PR #199): login tetap berhasil, tetapi
                    // benturan tidak lagi ditelan diam-diam — dicatat ke audit + log agar Admin punya
                    // jejak yang terlihat dan bisa melakukan remediasi atas konflik email canonical.
                    Log::warning('Sinkronisasi email canonical gagal karena benturan index unik employees_email_pribadi_unique', [
                        'employee_id' => $employee->id,
                        'user_id' => $user->id,
                    ]);
                    $this->auditEmailSyncConflict($user, $employee, $previousEmail, $verifiedEmail, $request);
                }
            }
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
                    // Kolom email lama tanpa index unik: hanya cocokkan pegawai aktif;
                    // pegawai nonaktif hanya memegang email_pribadi kanonisnya.
                    ->orWhere(fn ($query) => $query->withTrashed()->whereRaw('lower(email) = ?', [$matchedEmail]));
            })->limit(2)->get();
        }

        return Employee::whereRaw('lower('.$employeeField.') = ?', [$matchedEmail])->limit(2)->get();
    }

    private function emailIsOwnedByAnotherEmployee(Employee $employee, string $email): bool
    {
        return Employee::withTrashed()
            ->whereKeyNot($employee->id)
            ->where(function ($query) use ($email): void {
                $query
                    ->whereRaw('lower(email_pribadi) = ?', [$email])
                    ->orWhereRaw('lower(email) = ?', [$email]);
            })
            ->exists();
    }

    /**
     * Mencatat perubahan email canonical (email_pribadi) hasil sinkronisasi Keycloak ke audit.
     *
     * Payload memakai kunci non-sensitif saja; email bukan nomor identitas sehingga tidak
     * disamarkan oleh AuditPayloadMasker, dan tidak pernah menyimpan raw claim/token Keycloak.
     */
    private function auditEmailSync(User $user, Employee $employee, string $previousEmail, string $verifiedEmail, Request $request): void
    {
        AuditService::logAs(
            $user->id,
            $user->name,
            'EMAIL_SYNCED',
            'Employee',
            $employee->id,
            ['email_pribadi' => $previousEmail],
            ['email_pribadi' => $verifiedEmail, 'source' => 'keycloak'],
            $request,
        );
    }

    /**
     * Mencatat benturan index unik saat sinkronisasi email canonical tidak dapat diselesaikan.
     *
     * Login tetap berhasil (keputusan produk PR #199); email pegawai tidak berubah. Konflik
     * tercatat di audit + log supaya Admin melihat ada email canonical yang belum terselesaikan.
     */
    private function auditEmailSyncConflict(User $user, Employee $employee, string $previousEmail, string $attemptedEmail, Request $request): void
    {
        AuditService::logAs(
            $user->id,
            $user->name,
            'EMAIL_CONFLICT',
            'Employee',
            $employee->id,
            ['email_pribadi' => $previousEmail],
            ['email_pribadi' => $previousEmail, 'attempted_email' => $attemptedEmail, 'source' => 'keycloak'],
            $request,
        );
    }

    /**
     * Memastikan unique violation berasal dari index email_pribadi (functional index
     * case-insensitive), bukan constraint unik lain pada tabel pegawai.
     *
     * Deteksi portabel: PostgreSQL memakai SQLSTATE 23505 (unique_violation) dan SQLite
     * memetakan semua pelanggaran integritas ke 23000 sehingga wajib menegaskan email_pribadi.
     */
    private function isEmailPribadiConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverDiagnostic = (string) ($exception->errorInfo[2] ?? '');

        if ($sqlState === '23505') {
            preg_match('/unique constraint ["\']([^"\']+)["\']/i', $driverDiagnostic, $matches);

            return ($matches[1] ?? null) === 'employees_email_pribadi_unique';
        }

        return $sqlState === '23000'
            && preg_match(
                '/unique constraint failed:\s*(?:employees\.email_pribadi\b|index ["\']employees_email_pribadi_unique["\'])/i',
                $driverDiagnostic,
            ) === 1;
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
