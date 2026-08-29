<?php

namespace App\Actions\Auth;

use App\Models\Employee;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menyediakan daftar pemetaan akun dengan relasi users.employee_id sebagai
 * sumber kebenaran. Email hanya dipakai untuk membaca satu akun legacy yang
 * aman dan tidak ambigu; ia tidak pernah menjadi relasi canonical.
 */
class ListUserMappingsAction
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $query = Employee::query()
            ->select([
                'id',
                'nama_lengkap',
                'nip',
                'email',
                'email_pribadi',
            ])
            ->with([
                'user:id,employee_id,email,keycloak_id,role',
            ]);

        if (filled($filters['search'] ?? null)) {
            $this->applySearchFilter($query, (string) $filters['search']);
        }

        if (filled($filters['role'] ?? null)) {
            $role = (string) $filters['role'];

            $this->whereEffectiveUser($query, function ($userQuery, string $table) use ($role): void {
                $userQuery->where("{$table}.role", $role);
            });
        }

        if (filled($filters['status'] ?? null)) {
            $this->applyStatusFilter($query, (string) $filters['status']);
        }

        $perPage = (int) ($filters['per_page'] ?? 10);
        $paginator = $query
            ->orderBy('nama_lengkap')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        $legacyUsersByEmployeeId = $this->legacyUsersByEmployeeId($paginator->getCollection());

        return $paginator->through(
            fn (Employee $employee): array => $this->toTableRow($employee, $legacyUsersByEmployeeId),
        );
    }

    private function applySearchFilter(Builder $query, string $search): void
    {
        $keyword = '%'.mb_strtolower($search).'%';

        $query->where(function (Builder $filterQuery) use ($keyword): void {
            $filterQuery
                ->whereRaw('lower(employees.nama_lengkap) like ?', [$keyword])
                ->orWhereRaw('lower(employees.nip) like ?', [$keyword])
                ->orWhereRaw('lower(employees.email) like ?', [$keyword])
                ->orWhereRaw('lower(employees.email_pribadi) like ?', [$keyword])
                ->orWhereHas('user', function (Builder $userQuery) use ($keyword): void {
                    $userQuery->whereRaw('lower(users.email) like ?', [$keyword]);
                })
                ->orWhereExists(function (QueryBuilder $legacyUserQuery) use ($keyword): void {
                    $this->constrainSafeLegacyUser(
                        $legacyUserQuery,
                        function ($userQuery, string $table) use ($keyword): void {
                            $userQuery->whereRaw("lower({$table}.email) like ?", [$keyword]);
                        },
                    );
                });
        });
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        match ($status) {
            'belum_ada_user' => $this->whereWithoutEffectiveUser($query),
            'identifier_kosong' => $this->whereEffectiveUser($query, function ($userQuery, string $table): void {
                $this->whereBlankColumn($userQuery, $table, 'keycloak_id');
            }),
            'role_kosong' => $this->whereEffectiveUser($query, function ($userQuery, string $table): void {
                $this->whereFilledColumn($userQuery, $table, 'keycloak_id');
                $this->whereBlankColumn($userQuery, $table, 'role');
            }),
            'terhubung' => $this->whereEffectiveUser($query, function ($userQuery, string $table): void {
                $this->whereFilledColumn($userQuery, $table, 'keycloak_id');
                $this->whereFilledColumn($userQuery, $table, 'role');
            }),
        };
    }

    /**
     * Menambahkan kondisi untuk user canonical atau satu user legacy yang
     * terbukti aman bagi employee pada row yang sama.
     *
     * @param  Builder<Employee>  $query
     * @param  Closure(mixed, string): void  $constraint
     */
    private function whereEffectiveUser(Builder $query, Closure $constraint): void
    {
        // Row yang dievaluasi dibatasi klasifikasi aktif: pegawai nonaktif (termasuk
        // hasil backfill) tidak boleh dianggap memiliki user efektif — baik user
        // canonical maupun fallback legacy — agar daftar/filter tidak inkonsisten
        // dengan hitungan kandidat aktif yang sama (lihat constrainSafeLegacyUser).
        $query
            ->whereActiveStatus()
            ->where(function (Builder $effectiveUserQuery) use ($constraint): void {
                $effectiveUserQuery
                    ->whereHas('user', function (Builder $userQuery) use ($constraint): void {
                        $constraint($userQuery, 'users');
                    })
                    ->orWhereExists(function (QueryBuilder $legacyUserQuery) use ($constraint): void {
                        $this->constrainSafeLegacyUser($legacyUserQuery, $constraint);
                    });
            });
    }

    private function whereWithoutEffectiveUser(Builder $query): void
    {
        $query
            ->whereDoesntHave('user')
            ->whereNotExists(function (QueryBuilder $legacyUserQuery): void {
                $this->constrainSafeLegacyUser($legacyUserQuery);
            });
    }

    /**
     * Membuat EXISTS untuk fallback legacy. Query ini selalu menolak fallback
     * bila employee sudah mempunyai user canonical atau jika satu email cocok
     * dengan lebih dari satu employee aktif.
     *
     * @param  Closure(mixed, string): void|null  $constraint
     */
    private function constrainSafeLegacyUser(QueryBuilder $query, ?Closure $constraint = null): void
    {
        $activeGroupPlaceholders = implode(', ', array_fill(
            0,
            count(RefStatusPegawai::normalizedActiveGroups()),
            '?',
        ));

        $query
            ->selectRaw('1')
            ->from('users as legacy_users')
            ->whereNull('legacy_users.employee_id')
            ->whereNotNull('legacy_users.email')
            ->whereNotExists(function (QueryBuilder $canonicalUserQuery): void {
                $canonicalUserQuery
                    ->selectRaw('1')
                    ->from('users as canonical_users')
                    ->whereColumn('canonical_users.employee_id', 'employees.id');
            })
            ->where(function (QueryBuilder $emailQuery): void {
                $emailQuery
                    ->whereRaw('lower(legacy_users.email) = lower(employees.email)')
                    ->orWhereRaw('lower(legacy_users.email) = lower(employees.email_pribadi)');
            })
            ->whereRaw(<<<SQL
(select count(distinct legacy_employees.id)
from employees as legacy_employees
where exists (
    select 1
    from ref_status_pegawai as legacy_status
    where legacy_status.id = legacy_employees.status_pegawai_id
      and lower(trim(legacy_status.kelompok)) in ({$activeGroupPlaceholders})
)
and (lower(legacy_employees.email) = lower(legacy_users.email)
or lower(legacy_employees.email_pribadi) = lower(legacy_users.email))) = 1
SQL, RefStatusPegawai::normalizedActiveGroups());

        if ($constraint !== null) {
            $constraint($query, 'legacy_users');
        }
    }

    private function whereFilledColumn($query, string $table, string $column): void
    {
        $query
            ->whereNotNull("{$table}.{$column}")
            ->whereRaw("trim({$table}.{$column}) <> ''");
    }

    private function whereBlankColumn($query, string $table, string $column): void
    {
        $query->where(function ($blankColumnQuery) use ($table, $column): void {
            $blankColumnQuery
                ->whereNull("{$table}.{$column}")
                ->orWhereRaw("trim({$table}.{$column}) = ''");
        });
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<string, User>
     */
    private function legacyUsersByEmployeeId(Collection $employees): Collection
    {
        $emails = $employees
            ->flatMap(fn (Employee $employee): array => $this->employeeEmails($employee))
            ->unique()
            ->values()
            ->all();

        if ($emails === []) {
            return collect();
        }

        $legacyUsers = User::query()
            ->select(['id', 'employee_id', 'email', 'keycloak_id', 'role'])
            ->whereNull('employee_id')
            ->whereIn(DB::raw('lower(email)'), $emails)
            ->get();

        if ($legacyUsers->isEmpty()) {
            return collect();
        }

        $employeesByEmail = [];

        // Kandidat legacy memakai klasifikasi aktif yang sama dengan hitungan keamanan
        // SQL (constrainSafeLegacyUser): hanya pegawai berkelompok aktif yang dianggap
        // sebagai kandidat pemetaan, sehingga transformasi dan predikat tidak kontradiksi
        // (mis. satu pegawai aktif + satu hasil backfill nonaktif berbagi email).
        Employee::query()
            ->select(['id', 'email', 'email_pribadi'])
            ->whereActiveStatus()
            ->where(function (Builder $query) use ($emails): void {
                $query
                    ->whereIn(DB::raw('lower(email)'), $emails)
                    ->orWhereIn(DB::raw('lower(email_pribadi)'), $emails);
            })
            ->get()
            ->each(function (Employee $employee) use (&$employeesByEmail): void {
                foreach ($this->employeeEmails($employee) as $email) {
                    $employeesByEmail[$email] ??= [];
                    $employeesByEmail[$email][] = $employee->id;
                }
            });

        $canonicalEmployeeIds = $employees
            ->filter(fn (Employee $employee): bool => $employee->user !== null)
            ->pluck('id')
            ->flip();
        $legacyUsersByEmployeeId = collect();
        $ambiguousEmployeeIds = [];

        foreach ($legacyUsers as $user) {
            $email = $this->normalizeEmail($user->email);
            $candidateEmployeeIds = $email
                ? array_values(array_unique($employeesByEmail[$email] ?? []))
                : [];

            if (count($candidateEmployeeIds) !== 1) {
                continue;
            }

            $employeeId = $candidateEmployeeIds[0];

            if ($canonicalEmployeeIds->has($employeeId) || isset($ambiguousEmployeeIds[$employeeId])) {
                continue;
            }

            if ($legacyUsersByEmployeeId->has($employeeId)) {
                $legacyUsersByEmployeeId->forget($employeeId);
                $ambiguousEmployeeIds[$employeeId] = true;

                continue;
            }

            $legacyUsersByEmployeeId->put($employeeId, $user);
        }

        return $legacyUsersByEmployeeId;
    }

    /**
     * @param  Collection<string, User>  $legacyUsersByEmployeeId
     * @return array<string, mixed>
     */
    private function toTableRow(Employee $employee, Collection $legacyUsersByEmployeeId): array
    {
        $user = $employee->user ?? $legacyUsersByEmployeeId->get($employee->id);
        $mappingStatus = $this->mappingStatus($user);

        return [
            'id' => $employee->id,
            'nama' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'mapped_email' => $user?->email ?? $this->employeeEmails($employee)[0] ?? '',
            'keycloak_id' => $user?->keycloak_id,
            'role' => $user?->role,
            'mapping_status' => $mappingStatus,
            'mapping_status_label' => $this->mappingStatusLabel($mappingStatus),
        ];
    }

    private function mappingStatus(?User $user): string
    {
        if ($user === null) {
            return 'belum_ada_user';
        }

        if (blank($user->keycloak_id)) {
            return 'identifier_kosong';
        }

        if (blank($user->role)) {
            return 'role_kosong';
        }

        return 'terhubung';
    }

    private function mappingStatusLabel(string $status): string
    {
        return match ($status) {
            'belum_ada_user' => 'Belum Ada User Lokal',
            'identifier_kosong' => 'Identifier Keycloak Kosong',
            'role_kosong' => 'Role Belum Ditetapkan',
            'terhubung' => 'Terhubung',
        };
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

    private function normalizeEmail(?string $email): ?string
    {
        $email = is_string($email) ? strtolower(trim($email)) : null;

        return $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
