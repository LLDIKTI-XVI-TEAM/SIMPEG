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
        // Serialisasikan revert terhadap row users agar dua request paralel tidak
        // sama-sama membaca state sebelum revert dan menulis audit yang menduplikasi.
        DB::transaction(function () use ($user, $request): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Jika tidak sedang dalam mode simulasi, tidak perlu lakukan apa-apa
            if ($locked->temporary_role === null) {
                return;
            }

            $oldValues = [
                'role' => $locked->role,
                'temporary_role' => $locked->temporary_role,
                'temporary_permission' => $locked->temporary_permission,
                'temporary_role_started_at' => $locked->temporary_role_started_at?->toIso8601String(),
                'temporary_role_switched_by' => $locked->temporary_role_switched_by,
            ];

            $locked->forceFill([
                'temporary_role' => null,
                'temporary_permission' => null,
                'temporary_role_started_at' => null,
                'temporary_role_switched_by' => null,
            ]);

            $locked->save();

            AuditService::logOrFail(
                'REVERT_ROLE',
                'User',
                $locked->id,
                $oldValues,
                [
                    'role' => $locked->role,
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
