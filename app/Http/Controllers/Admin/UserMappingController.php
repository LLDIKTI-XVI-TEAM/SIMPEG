<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateUserMappingRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Str;

class UserMappingController extends Controller
{
    public function index()
    {
        $pegawai = Employee::orderBy('nama_lengkap')->get();
        $users = User::all()->keyBy('email');

        $mappedPegawai = $pegawai->map(function ($p) use ($users) {
            $email = $p->email ?? '';

            // Find match in users table by email
            $user = null;
            if ($email) {
                $user = $users->get($email);
            }

            return [
                'id' => $p->id,
                'nama' => $p->nama_lengkap,
                'nip' => $p->nip,
                'jenis' => $p->jenisPegawai?->nama ?? '-',
                'email' => $email,
                'keycloak_id' => $user ? $user->keycloak_id : null,
                'role' => $user ? $user->role : 'pegawai',
                'is_connected' => $user && ! empty($user->keycloak_id),
                'mapped_email' => $user ? $user->email : $email,
            ];
        })->toArray();

        return view('admin.user-management.index', [
            'pegawai' => $mappedPegawai,
            'title' => 'User Management / Kelola Akses User',
        ]);
    }

    public function update(UpdateUserMappingRequest $request)
    {
        $email = $request->input('email');
        $keycloakId = trim($request->input('keycloak_id', ''));
        $role = $request->input('role');

        // Enforcement: satu keycloak_id hanya bisa di-mapping ke satu pegawai
        if (! empty($keycloakId)) {
            $existing = User::where('keycloak_id', $keycloakId)
                ->where('email', '!=', $email)
                ->first();

            if ($existing) {
                return back()->with('error', 'Keycloak ID tersebut sudah digunakan oleh pegawai lain!');
            }
        }

        // Cari employee berdasarkan email untuk menyimpan relasi employee_id
        $employee = Employee::where(function ($q) use ($email): void {
            $q->whereRaw('lower(email) = ?', [strtolower($email)])
                ->orWhereRaw('lower(email_pribadi) = ?', [strtolower($email)]);
        })->first();

        $user = User::firstOrNew(['email' => $email]);

        $oldValues = [
            'keycloak_id' => $user->keycloak_id,
            'role' => $user->role ?? 'pegawai',
            'employee_id' => $user->employee_id,
        ];

        $user->fill([
            'keycloak_id' => $keycloakId ?: null,
            'role' => $role,
            'employee_id' => $employee?->id,
        ]);

        if (! $user->exists) {
            $user->name = $email;
            $user->password = Str::random(48);
        }

        $user->save();

        AuditService::log(
            'UPDATE',
            'User',
            $user->id,
            $oldValues,
            [
                'keycloak_id' => $keycloakId ?: null,
                'role' => $role,
                'employee_id' => $employee?->id,
            ],
            $request,
        );

        return back()->with('success', 'Akses User berhasil diperbarui!');
    }
}
