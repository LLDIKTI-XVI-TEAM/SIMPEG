<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSsoUserSeeder extends Seeder
{
    public function run(): void
    {
        // Akun demo hanya untuk pengembangan/pengujian. Jika ditanam di produksi,
        // akun demo ini akan mengisi role internal yang seharusnya dikelola admin.
        // Gerbang fail-closed: hanya local dan testing yang menanam akun demo ini.
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach (config('services.keycloak.demo_users', []) as $demoUser) {
            $username = trim((string) ($demoUser['username'] ?? ''));
            $email = trim((string) ($demoUser['email'] ?? ''));

            if ($username === '' || $email === '') {
                continue;
            }

            $employee = Employee::where('email', $email)->first();

            if (! $employee) {
                $employee = Employee::factory()->create([
                    'nama_lengkap' => $demoUser['name'],
                    'email' => $email,
                    'status_aktif' => 'Aktif',
                    'role' => $demoUser['role'],
                ]);
            } else {
                $employee->update([
                    'nama_lengkap' => $demoUser['name'],
                    'status_aktif' => 'Aktif',
                    'role' => $demoUser['role'],
                ]);
            }

            $user = User::where('keycloak_username', $username)->first()
                ?? User::where('email', $email)->first()
                ?? new User;

            $user->fill([
                'name' => $demoUser['name'],
                'email' => $email,
                'keycloak_username' => $username,
                'role' => $demoUser['role'],
                'employee_id' => $employee->id,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'password' => $demoUser['password'] ?? $username,
            ]);

            $user->save();

            $this->command?->info("Demo user '{$username}' set to role: {$demoUser['role']}.");
        }
    }
}
