<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            ReferenceSeeder::class,
            RbacSeeder::class,
        ]);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        User::firstOrCreate(
            ['keycloak_username' => 'demo-klabat'],
            [
                'name' => 'Demo Klabat',
                'email' => 'demo-klabat@dev.local',
                'role' => 'super_admin',
                'password' => null,
                'email_verified_at' => now(),
            ],
        );
    }
}
