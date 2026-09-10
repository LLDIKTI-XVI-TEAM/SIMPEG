<?php

namespace App\Actions\Auth;

use App\Exceptions\SwitchRoleConflictException;
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
        // Serialisasikan transisi terhadap row users agar dua request paralel tidak
        // sama-sama membaca state lama dan menghasilkan jejak audit SWITCH_ROLE ganda
        // yang mengklaim transisi dari state yang sama.
        DB::transaction(function () use ($user, $targetRole, $temporaryPermission, $request): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Simulasi sudah aktif: tolak switch kedua. Dua request switch yang lolos middleware
            // secara bersamaan bisa menunggu lock; setelah memperolehnya, state terkunci sudah
            // menampilkan simulasi berjalan sehingga switch ulang harus ditolak agar otorisasi
            // konsisten dengan role efektif saat itu (bukan hanya role asli).
            if ($locked->temporary_role !== null) {
                throw new SwitchRoleConflictException('Simulasi role sudah aktif. Lakukan revert terlebih dahulu sebelum switch kembali.');
            }

            // Validasi ulang dari state terkunci (bukan instance request) agar fail-closed.
            // Kegagalan ini adalah konflik otorisasi akibat state berubah di antara validasi
            // request dan lock (mis. demosi paralel); dilempar sebagai exception khusus agar
            // direspons 403/konflik, bukan 500.
            if ($targetRole === $locked->role) {
                throw new SwitchRoleConflictException('Tidak dapat switch ke role yang sama dengan role asli.');
            }

            if (! $locked->canInitiateSwitchRole()) {
                throw new SwitchRoleConflictException('Hanya Super Admin, Admin Kepegawaian, atau Pimpinan dengan permission switch role yang dapat memulai simulasi role.');
            }

            if (! $locked->canSwitchToRole($targetRole)) {
                throw new SwitchRoleConflictException("Tidak dapat switch ke role {$targetRole}. Role target harus lebih rendah dari role asli.");
            }

            // Simpan state lama untuk audit dari row yang dikunci.
            $oldValues = [
                'role' => $locked->role,
                'temporary_role' => $locked->temporary_role,
                'temporary_permission' => $locked->temporary_permission,
            ];

            // Update temporary_role dan metadata
            $locked->forceFill([
                'temporary_role' => $targetRole,
                'temporary_permission' => $temporaryPermission,
                'temporary_role_started_at' => now(),
                'temporary_role_switched_by' => $locked->id,
            ]);

            $locked->save();

            AuditService::logOrFail(
                'SWITCH_ROLE',
                'User',
                $locked->id,
                $oldValues,
                [
                    'role' => $locked->role,
                    'temporary_role' => $targetRole,
                    'temporary_permission' => $temporaryPermission,
                    'temporary_role_started_at' => $locked->temporary_role_started_at?->toIso8601String(),
                    'temporary_role_switched_by' => $locked->id,
                ],
                $request,
            );
        });
    }
}
