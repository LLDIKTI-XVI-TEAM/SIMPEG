<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RevertRoleAction
{
    /**
     * Mengembalikan role pengguna ke role aslinya dan membersihkan state simulasi role.
     */
    public function execute(User $user, Request $request): void
    {
        // Jika tidak sedang dalam mode simulasi, tidak perlu lakukan apa-apa
        if ($user->temporary_role === null) {
            return;
        }

        $oldValues = [
            'role' => $user->role,
            'temporary_role' => $user->temporary_role,
            'temporary_permission' => $user->temporary_permission,
            'temporary_role_started_at' => $user->temporary_role_started_at?->toIso8601String(),
            'temporary_role_switched_by' => $user->temporary_role_switched_by,
        ];

        $user->forceFill([
            'temporary_role' => null,
            'temporary_permission' => null,
            'temporary_role_started_at' => null,
            'temporary_role_switched_by' => null,
        ]);

        DB::transaction(function () use ($user, $oldValues, $request): void {
            $user->save();

            AuditService::logOrFail(
                'REVERT_ROLE',
                'User',
                $user->id,
                $oldValues,
                [
                    'role' => $user->role,
                    'temporary_role' => null,
                    'temporary_permission' => null,
                    'temporary_role_started_at' => null,
                    'temporary_role_switched_by' => null,
                ],
                $request,
            );
        });
    }
}
