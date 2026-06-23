<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSsoUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('KEYCLOAK_TEST_USERNAME', 'demo-klabat');

        if ($username === '') {
            return;
        }

        $user = User::firstOrNew(['keycloak_username' => $username]);
        $user->fill([
            'name' => 'Demo Klabat',
            'email' => 'demo-klabat@example.test',
            'role' => 'admin_kepegawaian',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        if (! $user->exists) {
            $user->password = Str::random(48);
        }

        $user->save();
    }
}
