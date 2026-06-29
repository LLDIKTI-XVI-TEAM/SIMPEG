<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoRolePimpinanSeeder extends Seeder
{
    public function run(): void
    {
        // Seeder demo pengalih peran untuk pengujian RBAC manual; membuat akun dengan
        // peran istimewa sehingga WAJIB mati di luar local/testing agar tidak menanam
        // akun istimewa di lingkungan produksi.
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $username = config('services.keycloak.test_username', 'demo-klabat');
        $email = 'demo-klabat@example.test';

        $employee = Employee::where('email', $email)->first();
        if (! $employee) {
            $employee = Employee::factory()->create([
                'nama_lengkap' => 'Demo Klabat (Pimpinan)',
                'email' => $email,
                'status_aktif' => 'Aktif',
            ]);
        } else {
            $employee->update(['nama_lengkap' => 'Demo Klabat (Pimpinan)']);
        }

        $user = User::firstOrNew(['keycloak_username' => $username]);
        $user->fill([
            'name' => 'Demo Klabat (Pimpinan)',
            'email' => $email,
            'role' => 'pimpinan',
            'employee_id' => $employee->id,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        if (! $user->exists) {
            $user->password = Str::random(48);
        }

        $user->save();

        $this->command->info("Demo user '{$username}' set to role: pimpinan.");
    }
}
