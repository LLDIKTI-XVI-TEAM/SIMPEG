<?php

namespace App\Services\Rbac;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Menyediakan capability UI dari RBAC internal dengan satu pembacaan permission per role dan request.
 */
class UiPermissionCapabilityService
{
    /** @var array<string, array<string, true>> */
    private array $permissionsByRole = [];

    private ?Request $cachedRequest = null;

    public function allows(?User $actor, string $permission): bool
    {
        // Capability menu harus mengikuti role efektif agar simulasi tidak mewarisi izin role asli.
        $role = trim((string) $actor?->getEffectiveRole());

        if ($role === '') {
            return false;
        }

        return isset($this->permissionsForRole($role)[$permission]);
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string, bool>
     */
    public function resolve(?User $actor, array $permissions): array
    {
        $resolved = [];

        foreach ($permissions as $permission) {
            $resolved[$permission] = $this->allows($actor, $permission);
        }

        return $resolved;
    }

    /** @return array<string, true> */
    private function permissionsForRole(string $role): array
    {
        $this->resetCacheForCurrentRequest();

        if (array_key_exists($role, $this->permissionsByRole)) {
            return $this->permissionsByRole[$role];
        }

        $permissionNames = DB::table('roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.name', $role)
            ->pluck('permissions.name')
            ->all();
        $permissions = [];
        foreach ($permissionNames as $name) {
            $permissions[(string) $name] = true;
        }

        return $this->permissionsByRole[$role] = $permissions;
    }

    /** Menjaga cache tetap satu-request, termasuk saat test kernel memakai container yang sama. */
    private function resetCacheForCurrentRequest(): void
    {
        $request = app()->bound('request') ? app('request') : null;

        if (! $request instanceof Request || $request === $this->cachedRequest) {
            return;
        }

        $this->permissionsByRole = [];
        $this->cachedRequest = $request;
    }
}
