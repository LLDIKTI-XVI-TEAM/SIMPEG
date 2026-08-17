<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SwitchRoleAction
{
    /**
     * Melakukan switch role ke role yang lebih rendah untuk tujuan demo/testing/support.
     * Switch role adalah simulasi role, bukan impersonasi identitas.
     * Identitas aktor, kepemilikan data, dan jejak audit tidak berubah.
     */
    public function execute(User $user, string $targetRole, Request $request, ?string $temporaryPermission = null): void
    {
        // Validasi: tidak boleh switch ke role yang sama
        if ($targetRole === $user->role) {
            throw new \InvalidArgumentException('Tidak dapat switch ke role yang sama dengan role asli.');
        }

        // Validasi: hanya boleh switch ke role yang lebih rendah
        if (! $user->canSwitchToRole($targetRole)) {
            throw new \InvalidArgumentException("Tidak dapat switch ke role {$targetRole}. Role target harus lebih rendah dari role asli.");
        }

        // Simpan state lama untuk audit
        $oldValues = [
            'role' => $user->role,
            'temporary_role' => $user->temporary_role,
            'temporary_permission' => $user->temporary_permission,
        ];

        // Update temporary_role dan metadata
        $user->forceFill([
            'temporary_role' => $targetRole,
            'temporary_permission' => $temporaryPermission,
            'temporary_role_started_at' => now(),
            'temporary_role_switched_by' => $user->id,
        ]);

        // Simpan dengan transaksi untuk memastikan atomicity antara state dan audit
        DB::transaction(function () use ($user, $targetRole, $temporaryPermission, $oldValues, $request): void {
            $user->save();

            AuditService::logOrFail(
                'SWITCH_ROLE',
                'User',
                $user->id,
                $oldValues,
                [
                    'role' => $user->role,
                    'temporary_role' => $targetRole,
                    'temporary_permission' => $temporaryPermission,
                    'temporary_role_started_at' => $user->temporary_role_started_at?->toIso8601String(),
                    'temporary_role_switched_by' => $user->id,
                ],
                $request,
            );
        });
    }
}
