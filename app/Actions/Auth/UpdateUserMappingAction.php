<?php

namespace App\Actions\Auth;

use App\Exceptions\UserMappingAuditException;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;
use App\Support\IdentifierMasker;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Memetakan satu akun SIMPEG/Keycloak ke satu pegawai canonical.
 *
 * `users` adalah source of truth. Email pegawai hanya dipakai untuk menemukan
 * satu akun legacy yang tidak memiliki employee_id dan tidak pernah menjadi
 * identifier mutasi dari request.
 */
class UpdateUserMappingAction
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{employee_id: string, keycloak_id: string, role: string}  $data
     */
    public function execute(array $data, Request $request): User
    {
        try {
            return DB::transaction(function () use ($data, $request): User {
                $employee = Employee::query()
                    ->lockForUpdate()
                    ->find($data['employee_id']);

                if (! $employee) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'Pegawai yang dipilih tidak ditemukan.',
                    ]);
                }

                $keycloakId = $this->normalizeIdentifier($data['keycloak_id']);

                if ($keycloakId === null) {
                    throw ValidationException::withMessages([
                        'keycloak_id' => 'Identifier Keycloak wajib diisi. Disconnect belum tersedia pada halaman ini.',
                    ]);
                }

                $user = $this->resolveUser($employee, $keycloakId);
                $isNewUser = ! $user->exists;
                $oldValues = $this->auditValues($user);

                if ($isNewUser) {
                    $user->fill([
                        'name' => $employee->nama_lengkap,
                        'email' => $this->emailForNewUser($employee),
                        'password' => Str::random(48),
                    ]);
                }

                $user->fill([
                    'employee_id' => $employee->id,
                    'keycloak_id' => $keycloakId,
                    'role' => $data['role'],
                ]);

                // Jika role asli akun diubah dan temporary_role tidak lagi valid (misalnya role diturunkan
                // sehingga temporary_role tidak lagi lebih rendah dari role baru), batalkan simulasi.
                $simulationCancelled = false;
                if ($user->temporary_role !== null && ! $this->isTemporaryRoleValidForOriginalRole($user->role, $user->temporary_role)) {
                    $simulationCancelled = true;
                    $user->temporary_role = null;
                    $user->temporary_permission = null;
                    $user->temporary_role_started_at = null;
                    $user->temporary_role_switched_by = null;
                }

                $user->save();

                try {
                    $this->audit->logOrFail(
                        $isNewUser ? 'CREATE' : 'UPDATE',
                        'User',
                        $user->id,
                        $oldValues,
                        $this->auditValues($user),
                        $request,
                    );

                    // Pembatalan simulasi karena perubahan role juga wajib diaudit agar jejak
                    // SWITCH_ROLE tidak menggantung tanpa akhir yang dapat ditelusuri.
                    if ($simulationCancelled) {
                        $this->audit->logOrFail(
                            'REVERT_ROLE',
                            'User',
                            $user->id,
                            $oldValues,
                            $this->auditValues($user),
                            $request,
                        );
                    }
                } catch (Throwable $exception) {
                    throw new UserMappingAuditException($exception);
                }

                return $user;
            }, 3);
        } catch (UserMappingAuditException) {
            throw ValidationException::withMessages([
                'mapping' => 'Pemetaan dibatalkan karena audit tidak dapat dicatat. Silakan hubungi administrator sistem.',
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages($this->uniqueConstraintMessages($exception));
        }
    }

    private function resolveUser(Employee $employee, string $keycloakId): User
    {
        $employeeUser = User::query()
            ->where('employee_id', $employee->id)
            ->lockForUpdate()
            ->first();

        $keycloakUser = User::query()
            ->where('keycloak_id', $keycloakId)
            ->lockForUpdate()
            ->first();

        if ($keycloakUser && $keycloakUser->employee_id !== null && $keycloakUser->employee_id !== $employee->id) {
            throw ValidationException::withMessages([
                'keycloak_id' => 'Identifier Keycloak tersebut sudah dipetakan ke pegawai lain.',
            ]);
        }

        if ($employeeUser && $keycloakUser && ! $employeeUser->is($keycloakUser)) {
            throw ValidationException::withMessages([
                'keycloak_id' => 'Pegawai ini sudah memiliki akun berbeda. Identifier Keycloak tidak dapat dipindahkan otomatis.',
            ]);
        }

        if ($employeeUser) {
            return $employeeUser;
        }

        if ($keycloakUser) {
            return $keycloakUser;
        }

        return $this->findLegacyUser($employee) ?? new User;
    }

    private function isTemporaryRoleValidForOriginalRole(?string $originalRole, string $temporaryRole): bool
    {
        $originalRank = User::ROLE_RANKS[$originalRole] ?? null;
        $temporaryRank = User::ROLE_RANKS[$temporaryRole] ?? null;

        return $originalRank !== null && $temporaryRank !== null && $temporaryRank < $originalRank;
    }

    private function findLegacyUser(Employee $employee): ?User
    {
        $emails = $this->employeeEmails($employee);

        if ($emails === []) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($emails), '?'));
        $candidates = User::query()
            ->whereRaw("lower(email) in ({$placeholders})", $emails)
            ->lockForUpdate()
            ->get();

        if ($candidates->count() > 1) {
            throw ValidationException::withMessages([
                'employee_id' => 'Ditemukan lebih dari satu akun legacy yang cocok dengan email pegawai. Pemetaan tidak dilakukan otomatis.',
            ]);
        }

        $user = $candidates->first();

        if ($user && $user->employee_id !== null && $user->employee_id !== $employee->id) {
            throw ValidationException::withMessages([
                'employee_id' => 'Email pegawai sudah digunakan oleh akun yang dipetakan ke pegawai lain.',
            ]);
        }

        return $user;
    }

    private function emailForNewUser(Employee $employee): string
    {
        $email = $this->employeeEmails($employee)[0] ?? null;

        if ($email === null) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pegawai harus memiliki email valid sebelum akun SIMPEG dapat dibuat.',
            ]);
        }

        return $email;
    }

    /** @return list<string> */
    private function employeeEmails(Employee $employee): array
    {
        $emails = [];

        foreach (['email', 'email_pribadi'] as $attribute) {
            $value = $employee->getRawOriginal($attribute);
            $normalized = $this->normalizeEmail(is_string($value) ? $value : null);

            if ($normalized !== null) {
                $emails[] = $normalized;
            }
        }

        return array_values(array_unique($emails));
    }

    private function normalizeIdentifier(string $identifier): ?string
    {
        $identifier = trim($identifier);

        return $identifier === '' ? null : $identifier;
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = is_string($email) ? strtolower(trim($email)) : null;

        return $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** @return array<string, string|null> */
    private function auditValues(User $user): array
    {
        return [
            'employee_id' => $user->employee_id,
            'role' => $user->role,
            'mapping_status' => $user->keycloak_id ? 'connected' : 'disconnected',
            'keycloak_id_masked' => IdentifierMasker::mask($user->keycloak_id),
            'temporary_role' => $user->temporary_role,
            'temporary_permission' => $user->temporary_permission,
            'temporary_role_started_at' => $user->temporary_role_started_at?->toIso8601String(),
            'temporary_role_switched_by' => $user->temporary_role_switched_by,
        ];
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23505'
            || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }

    /** @return array<string, string> */
    private function uniqueConstraintMessages(QueryException $exception): array
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'employee_id')) {
            return [
                'employee_id' => 'Pegawai tersebut sudah dipetakan ke akun lain. Muat ulang halaman sebelum mencoba kembali.',
            ];
        }

        return [
            'keycloak_id' => 'Identifier Keycloak tersebut sudah digunakan oleh pegawai lain. Muat ulang halaman sebelum mencoba kembali.',
        ];
    }
}
