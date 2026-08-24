<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SsoRoleMappedAccountSeeder extends Seeder
{
    /**
     * Menanam pegawai + user placeholder untuk setiap email yang terdaftar di
     * config services.keycloak.role_mapping, sehingga login SSO pertama akun
     * tersebut langsung menemukan tepat satu pegawai dan mendapat role internalnya.
     *
     * Sama seperti akun demo, seeder ini fail-closed: hanya berjalan di local/testing.
     * Di produksi, pegawai dengan email itu dibuat lewat alur admin/import yang normal.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach ((array) config('services.keycloak.role_mapping', []) as $email => $role) {
            $email = trim((string) $email);
            $role = trim((string) $role);

            if ($email === '' || $role === '') {
                continue;
            }

            $employee = Employee::where('email', $email)->first();

            if (! $employee) {
                $employee = Employee::factory()->create([
                    'nama_lengkap' => $this->displayName($email),
                    'email' => $email,
                    'status_aktif' => 'Aktif',
                    'role' => $role,
                ]);
            } else {
                $employee->update([
                    'status_aktif' => 'Aktif',
                    'role' => $role,
                ]);
            }

            $user = User::where('email', $email)->first() ?? new User;

            $user->fill([
                'name' => $this->displayName($email),
                'email' => $email,
                'role' => $role,
                'employee_id' => $employee->id,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'password' => Str::random(48),
            ]);

            $user->save();

            $this->command?->info("SSO mapped account '{$email}' seeded with role: {$role}.");
        }
    }

    private function displayName(string $email): string
    {
        $localPart = strstr($email, '@', true) ?: $email;

        return ucwords(str_replace(['.', '_', '-'], ' ', $localPart));
    }
}