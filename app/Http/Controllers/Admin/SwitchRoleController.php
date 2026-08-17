<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Auth\RevertRoleAction;
use App\Actions\Auth\SwitchRoleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SwitchRoleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchRoleController extends Controller
{
    /**
     * Melakukan switch role ke role yang lebih rendah.
     */
    public function switchRole(SwitchRoleRequest $request, SwitchRoleAction $action): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $targetRole = $validated['target_role'];
        $temporaryPermission = $validated['temporary_permission'] ?? null;

        $action->execute($user, $targetRole, $request, $temporaryPermission);

        $roleLabels = [
            'admin_kepegawaian' => 'Admin Kepegawaian',
            'pimpinan' => 'Pimpinan',
            'kepala_bagian' => 'Kepala Bagian',
            'pegawai' => 'Pegawai',
        ];
        $targetLabel = $roleLabels[$targetRole] ?? $targetRole;

        return redirect()->route('dashboard')->with(
            'success',
            "Mode simulasi aktif. Anda sekarang melihat sistem sebagai {$targetLabel}."
        );
    }

    /**
     * Mengembalikan role pengguna ke role aslinya.
     */
    public function revertRole(Request $request, RevertRoleAction $action): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $action->execute($user, $request);

        return redirect()->route('dashboard')->with(
            'success',
            'Mode simulasi role dinonaktifkan. Anda telah kembali ke role asli.'
        );
    }
}
