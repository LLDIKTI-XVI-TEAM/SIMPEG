<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Rbac\SaveRolePermissionMatrixAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rbac\SaveRolePermissionMatrixRequest;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RbacController extends Controller
{
    public function index(): View
    {
        $roles = Role::with('permissions')->get();
        $permissions = Permission::all();
        $permissionsByModule = $permissions->groupBy('module');

        return view('admin.rbac.index', [
            'roles' => $roles,
            'permissionsByModule' => $permissionsByModule,
            'title' => 'Role & Permission / RBAC',
        ]);
    }

    public function update(SaveRolePermissionMatrixRequest $request, SaveRolePermissionMatrixAction $action): RedirectResponse
    {
        /** @var array<string, array<int, string>> $matrix */
        $matrix = $request->validated()['matrix'] ?? [];
        $jumlahBerubah = $action->execute($matrix, $request);

        return back()->with('success', $jumlahBerubah > 0
            ? 'Hak akses peran (RBAC) berhasil diperbarui!'
            : 'Tidak ada perubahan hak akses peran yang perlu disimpan.');
    }
}
