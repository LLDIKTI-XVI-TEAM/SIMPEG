<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;

class RbacController extends Controller
{
    public function index()
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

    public function update(Request $request)
    {

        $matrix = $request->input('matrix', []);
        $roles = Role::with('permissions')->get();
        $changedLog = [];

        foreach ($roles as $role) {
            $oldPerms = $role->permissions->pluck('id')->toArray();
            // Get checked permission IDs for this role
            $newPerms = $matrix[$role->id] ?? [];

            sort($oldPerms);
            sort($newPerms);

            if ($oldPerms !== $newPerms) {
                // Sync the changes
                $role->permissions()->sync($newPerms);

                // Fetch names for logging
                $oldNames = Permission::whereIn('id', $oldPerms)->pluck('name')->toArray();
                $newNames = Permission::whereIn('id', $newPerms)->pluck('name')->toArray();

                $changedLog[$role->name] = [
                    'old' => $oldNames,
                    'new' => $newNames,
                ];
            }
        }

        if (! empty($changedLog)) {
            // Write Audit Log
            $dynamicLogs = session('dynamic_audit_logs', []);
            $newId = count($dynamicLogs) + 1;

            $dynamicLogs[] = [
                'id' => $newId,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'operator' => auth()->user()->name ?? 'super_admin',
                'event' => 'UPDATE_RBAC',
                'kategori' => 'user_management',
                'modul' => 'RBAC',
                'record_id' => 'multiple',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'old_values' => [
                    'note' => 'Perubahan hak akses peran',
                    'changes' => array_map(fn ($item) => $item['old'], $changedLog),
                ],
                'new_values' => [
                    'changes' => array_map(fn ($item) => $item['new'], $changedLog),
                ],
            ];

            session(['dynamic_audit_logs' => $dynamicLogs]);
        }

        return back()->with('success', 'Hak akses peran (RBAC) berhasil diperbarui!');
    }
}
