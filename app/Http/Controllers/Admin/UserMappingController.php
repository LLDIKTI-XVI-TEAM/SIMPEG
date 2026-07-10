<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserMappingController extends Controller
{
    public function index()
    {

        $pegawai = Employee::orderBy('nama_lengkap')->get();
        $users = User::all()->keyBy('email');

        $mappedPegawai = $pegawai->map(function ($p) use ($users) {
            $email = $p->email ?? '';
            $emailDinas = $p->email ?? ''; // No email_dinas in Employee

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

    public function update(Request $request)
    {

        $request->validate([
            'email' => 'required|email',
            'keycloak_id' => 'nullable|string',
            'role' => 'required|string|in:super_admin,admin_kepegawaian,pimpinan,kepala_bagian,pegawai',
        ]);

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

        $user = User::firstOrNew(['email' => $email]);

        $oldKeycloakId = $user->keycloak_id;
        $oldRole = $user->role ?? 'pegawai';

        $user->fill([
            'keycloak_id' => $keycloakId ?: null,
            'role' => $role,
        ]);

        if (! $user->exists) {
            $user->name = $email;
            $user->password = Str::random(48);
        }

        $user->save();

        // Write Audit Log
        $dynamicLogs = session('dynamic_audit_logs', []);
        $newId = count($dynamicLogs) + 1;

        $dynamicLogs[] = [
            'id' => $newId,
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'operator' => auth()->user()->name ?? 'super_admin',
            'event' => 'UPDATE_MAPPING',
            'kategori' => 'user_management',
            'modul' => 'UserMapping',
            'record_id' => (string) $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'old_values' => [
                'keycloak_id' => $oldKeycloakId,
                'role' => $oldRole,
            ],
            'new_values' => [
                'keycloak_id' => $keycloakId ?: null,
                'role' => $role,
            ],
        ];

        session(['dynamic_audit_logs' => $dynamicLogs]);

        return back()->with('success', 'Akses User berhasil diperbarui!');
    }
}
