<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSsoUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = config('services.keycloak.test_username', 'demo-klabat');

        if ($username === '') {
            return;
        }

        $user = User::firstOrNew(['keycloak_username' => $username]);
        $user->fill([
            'name' => 'Demo Klabat',
            'email' => 'demo-klabat@example.test',
            'role' => 'super_admin',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        if (! $user->exists) {
            $user->password = Str::random(48);
        }

        $user->save();
    }
}
