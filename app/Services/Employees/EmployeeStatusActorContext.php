<?php

namespace App\Services\Employees;

use App\Exceptions\MissingEmployeeStatusTransitionProvenanceException;
use App\Models\EmployeeStatusTransition;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Snapshot immutable aktor dan keputusan otorisasi saat jadwal dibuat.
 *
 * Scheduler membangun ulang object ini hanya dari row transisi, tanpa membaca role,
 * permission, atau state simulasi user yang dapat berubah setelah penjadwalan.
 */
final readonly class EmployeeStatusActorContext
{
    private const LOCAL_BYPASS_ACTOR_ID = '00000000-0000-0000-0000-000000000000';

    private const MISSING_USER_AGENT = 'tidak-dikirim';

    private function __construct(
        public string $actorUserId,
        public string $actorName,
        public string $originalRole,
        public string $effectiveRole,
        public string $authorizationPermission,
        public string $authorizationAction,
        public bool $simulation,
        public string $ipAddress,
        public string $userAgent,
        public ?string $linkedUserId,
    ) {}

    /** Menangkap role efektif dan metadata request setelah permission diverifikasi. */
    public static function capture(
        Request $request,
        string $authorizationPermission,
        string $authorizationAction,
    ): self {
        $ipAddress = trim((string) $request->ip());
        $userAgent = trim((string) $request->userAgent());
        $userAgent = $userAgent === '' ? self::MISSING_USER_AGENT : $userAgent;

        $actor = $request->user() ?? auth()->user();

        // Bypass hanya menggantikan aktor ketika request memang tidak terautentikasi.
        // User sah tetap dicatat apa adanya walau flag pengujian lokal sedang aktif.
        if (! $actor instanceof User
            && app()->environment('local')
            && config('services.simpeg.disable_employee_api_auth')) {
            return new self(
                actorUserId: self::LOCAL_BYPASS_ACTOR_ID,
                actorName: 'SIMPEG Local API Bypass',
                originalRole: 'local_api_bypass',
                effectiveRole: 'local_api_bypass',
                authorizationPermission: $authorizationPermission,
                authorizationAction: $authorizationAction,
                simulation: false,
                ipAddress: $ipAddress === '' ? '127.0.0.1' : $ipAddress,
                userAgent: $userAgent,
                linkedUserId: null,
            );
        }

        if (! $actor instanceof User) {
            throw ValidationException::withMessages([
                'actor' => 'Aktor penjadwalan status tidak dapat diverifikasi.',
            ]);
        }

        if (! $actor->hasPermission($authorizationPermission)) {
            throw ValidationException::withMessages([
                'actor' => 'Aktor tidak memiliki permission untuk menjadwalkan perubahan status.',
            ]);
        }

        $name = trim((string) $actor->name);
        $originalRole = trim((string) $actor->role);
        $effectiveRole = trim((string) $actor->getEffectiveRole());
        if ($name === '' || $originalRole === '' || $effectiveRole === '' || $ipAddress === '') {
            throw ValidationException::withMessages([
                'actor' => 'Provenance aktor penjadwalan status belum lengkap.',
            ]);
        }

        if (! app(EmployeeLifecycleAuthorization::class)
            ->effectiveRoleAllows($effectiveRole, $authorizationPermission)) {
            throw ValidationException::withMessages([
                'actor' => 'Role efektif aktor tidak diizinkan untuk mengaktifkan kembali pegawai.',
            ]);
        }

        return new self(
            actorUserId: $actor->id,
            actorName: $name,
            originalRole: $originalRole,
            effectiveRole: $effectiveRole,
            authorizationPermission: $authorizationPermission,
            authorizationAction: $authorizationAction,
            simulation: $originalRole !== $effectiveRole,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            linkedUserId: $actor->id,
        );
    }

    /** Memulihkan provenance due hanya dari snapshot immutable pada row jadwal. */
    public static function fromTransition(EmployeeStatusTransition $transition): self
    {
        if (! in_array($transition->provenance_status, [
            EmployeeStatusTransition::PROVENANCE_CAPTURED,
        ], true)) {
            throw MissingEmployeeStatusTransitionProvenanceException::forTransition($transition->id);
        }

        $required = [
            $transition->actor_user_id_snapshot,
            $transition->actor_name_snapshot,
            $transition->actor_original_role,
            $transition->actor_effective_role,
            $transition->authorization_permission,
            $transition->authorization_action,
            $transition->actor_ip_address,
            $transition->actor_user_agent,
        ];

        if ($transition->actor_simulation === null
            || collect($required)->contains(fn (mixed $value): bool => ! is_string($value) || trim($value) === '')) {
            throw MissingEmployeeStatusTransitionProvenanceException::forTransition($transition->id);
        }

        return new self(
            actorUserId: (string) $transition->actor_user_id_snapshot,
            actorName: (string) $transition->actor_name_snapshot,
            originalRole: (string) $transition->actor_original_role,
            effectiveRole: (string) $transition->actor_effective_role,
            authorizationPermission: (string) $transition->authorization_permission,
            authorizationAction: (string) $transition->authorization_action,
            simulation: (bool) $transition->actor_simulation,
            ipAddress: (string) $transition->actor_ip_address,
            userAgent: (string) $transition->actor_user_agent,
            // FK ini boleh null setelah user dihapus; actorUserId di atas tetap immutable.
            linkedUserId: $transition->created_by_user_id,
        );
    }

    /** @return array<string, mixed> */
    public function transitionAttributes(): array
    {
        return [
            'actor_user_id_snapshot' => $this->actorUserId,
            'actor_name_snapshot' => $this->actorName,
            'actor_original_role' => $this->originalRole,
            'actor_effective_role' => $this->effectiveRole,
            'authorization_permission' => $this->authorizationPermission,
            'authorization_action' => $this->authorizationAction,
            'actor_simulation' => $this->simulation,
            'actor_ip_address' => $this->ipAddress,
            'actor_user_agent' => $this->userAgent,
            'provenance_status' => EmployeeStatusTransition::PROVENANCE_CAPTURED,
            'created_by_user_id' => $this->linkedUserId,
        ];
    }

    /** @return array<string, mixed> */
    public function auditContext(): array
    {
        $context = [
            '_original_role' => $this->originalRole,
            '_effective_role' => $this->effectiveRole,
            '_authorization_permission' => $this->authorizationPermission,
            '_authorization_action' => $this->authorizationAction,
        ];

        if ($this->simulation) {
            $context['_simulation'] = true;
        }

        return $context;
    }

    /** Mencegah provenance untuk action/permission lain dipakai pada jadwal ini. */
    public function assertAuthorizes(string $permission, string $action): void
    {
        if ($this->authorizationPermission !== $permission || $this->authorizationAction !== $action) {
            throw ValidationException::withMessages([
                'actor' => 'Provenance aktor tidak sesuai dengan jenis transisi status.',
            ]);
        }

        // Snapshot role efektif ikut menjadi bagian keputusan reaktivasi sehingga
        // provenance lama atau hasil manipulasi tidak cukup hanya membawa permission.
        if (! app(EmployeeLifecycleAuthorization::class)
            ->effectiveRoleAllows($this->effectiveRole, $permission)) {
            throw ValidationException::withMessages([
                'actor' => 'Role efektif aktor tidak diizinkan untuk mengaktifkan kembali pegawai.',
            ]);
        }
    }

    /** Due hanya memverifikasi action snapshot; permission tidak dihitung ulang dari state live. */
    public function assertAction(string $action): void
    {
        if ($this->authorizationAction !== $action) {
            throw ValidationException::withMessages([
                'actor' => 'Action provenance tidak sesuai dengan jenis transisi status.',
            ]);
        }
    }
}
