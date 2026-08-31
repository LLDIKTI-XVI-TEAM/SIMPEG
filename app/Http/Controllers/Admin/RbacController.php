<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Rbac\SaveRolePermissionMatrixAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rbac\SaveRolePermissionMatrixRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\CutiPermissionMatrixPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RbacController extends Controller
{
    public function index(): View
    {
        // Pastikan seluruh permission sistem dari katalog terdaftar di database
        $systemPermissions = [
            'employees.read' => ['module' => 'employees', 'description' => 'Melihat daftar data pegawai'],
            'employees.create' => ['module' => 'employees', 'description' => 'Membuat data pegawai'],
            'employees.update' => ['module' => 'employees', 'description' => 'Mengubah data pegawai'],
            'employees.import' => ['module' => 'employees', 'description' => 'Import data pegawai'],
            'employees.deactivate' => ['module' => 'employees', 'description' => 'Menonaktifkan data pegawai'],
            'employees.restore' => ['module' => 'employees', 'description' => 'Mengaktifkan kembali data pegawai nonaktif'],
            'employees.read_self' => ['module' => 'employees', 'description' => 'Melihat detail data pegawai milik sendiri'],
            'employee_histories.read' => ['module' => 'employee_histories', 'description' => 'Melihat riwayat pegawai (pangkat, jabatan, KGB, pendidikan, pengangkatan, status kepegawaian)'],
            'employee_histories.create' => ['module' => 'employee_histories', 'description' => 'Membuat entri riwayat pegawai'],
            'employee_histories.update' => ['module' => 'employee_histories', 'description' => 'Memperbarui riwayat pegawai'],
            'employee_histories.delete' => ['module' => 'employee_histories', 'description' => 'Menghapus riwayat pegawai'],
            'employee_histories.export' => ['module' => 'employee_histories', 'description' => 'Melihat dan mengunduh Laporan Riwayat Kepangkatan pegawai'],
            'discipline_records.read' => ['module' => 'discipline_records', 'description' => 'Melihat riwayat hukuman disiplin'],
            'discipline_records.create' => ['module' => 'discipline_records', 'description' => 'Membuat riwayat hukuman disiplin'],
            'employee_families.read' => ['module' => 'employee_families', 'description' => 'Melihat data keluarga pegawai'],
            'employee_families.create' => ['module' => 'employee_families', 'description' => 'Membuat data keluarga pegawai'],
            'employee_families.update' => ['module' => 'employee_families', 'description' => 'Mengubah data keluarga pegawai'],
            'employee_families.delete' => ['module' => 'employee_families', 'description' => 'Menonaktifkan data keluarga pegawai'],
            'hari_libur.read' => ['module' => 'hari_libur', 'description' => 'Melihat hari libur dan cuti bersama'],
            'hari_libur.create' => ['module' => 'hari_libur', 'description' => 'Membuat hari libur dan cuti bersama'],
            'hari_libur.update' => ['module' => 'hari_libur', 'description' => 'Mengubah hari libur dan cuti bersama'],
            'hari_libur.delete' => ['module' => 'hari_libur', 'description' => 'Menghapus hari libur dan cuti bersama'],
            'reference_tables.manage' => ['module' => 'reference_tables', 'description' => 'Mengelola data referensi SIMPEG'],
            'sk_requirements.manage' => ['module' => 'sk_requirements', 'description' => 'Mengelola matriks SK wajib per jenis pegawai'],
            'audit_logs.read' => ['module' => 'audit_logs', 'description' => 'Melihat audit log sistem'],
            'notifications.read' => ['module' => 'notifications', 'description' => 'Melihat notifikasi milik sendiri'],
            'notifications.update' => ['module' => 'notifications', 'description' => 'Menandai notifikasi milik sendiri sudah dibaca'],
            'users.switch_role' => ['module' => 'users', 'description' => 'Melakukan simulasi beralih ke role yang lebih rendah untuk demo/testing/support'],
            'cuti.create' => ['module' => 'cuti', 'description' => 'Mengajukan permohonan cuti'],
            'cuti.read_own' => ['module' => 'cuti', 'description' => 'Melihat pengajuan cuti milik sendiri'],
            'cuti.read_all' => ['module' => 'cuti', 'description' => 'Melihat seluruh pengajuan cuti (monitor)'],
            'cuti.approve' => ['module' => 'cuti', 'description' => 'Mengambil keputusan approval cuti sesuai assignment aktif'],
            'cuti.configure' => ['module' => 'cuti', 'description' => 'Mengonfigurasi approval chain cuti'],
            'cuti.balance.read' => ['module' => 'cuti', 'description' => 'Melihat saldo cuti'],
            'cuti.balance.reconcile' => ['module' => 'cuti', 'description' => 'Mencatat dan memperbaiki fakta pemakaian serta saldo cuti'],
            'cuti.manual.manage' => ['module' => 'cuti', 'description' => 'Mencatat, mengoreksi, dan membatalkan pemakaian cuti manual'],
            'cuti.proof.generate' => ['module' => 'cuti', 'description' => 'Membuat ulang bukti/formulir cuti resmi setelah approval final'],
            'cuti.kepala_lembaga_documents.manage' => ['module' => 'cuti', 'description' => 'Mengelola dokumen pendukung cuti Kepala Lembaga'],
            'dokumen_sk.read' => ['module' => 'dokumen_sk', 'description' => 'Melihat dokumen dan SK pegawai'],
            'ews.read' => ['module' => 'ews', 'description' => 'Melihat daftar EWS aktif seluruh pegawai'],
            'ews.configure' => ['module' => 'ews', 'description' => 'Mengonfigurasi parameter dan threshold EWS'],
        ];

        foreach ($systemPermissions as $name => $attributes) {
            $perm = Permission::firstOrCreate(['name' => $name], $attributes);
            if ($perm->wasRecentlyCreated && in_array($name, ['employee_histories.export', 'dokumen_sk.read', 'ews.read'])) {
                $rolesToAttach = Role::whereIn('name', ['admin_kepegawaian', 'pimpinan'])->get();
                foreach ($rolesToAttach as $r) {
                    $r->permissions()->syncWithoutDetaching([$perm->id]);
                }
            }
        }

        $roles = Role::with('permissions')->get();
        $permissions = Permission::all();
        $permissionsByModule = $permissions->groupBy('module');
        $lockedPermissionIdsByRole = $roles->mapWithKeys(function (Role $role) use ($permissions): array {
            $lockedPermissionIds = $permissions
                ->filter(fn (Permission $permission): bool => ! CutiPermissionMatrixPolicy::isAssignableToRole($permission->name, $role->name))
                ->pluck('id')
                ->values()
                ->all();

            return [$role->id => $lockedPermissionIds];
        })->all();

        return view('admin.rbac.index', [
            'roles' => $roles,
            'permissionsByModule' => $permissionsByModule,
            'lockedPermissionIdsByRole' => $lockedPermissionIdsByRole,
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
