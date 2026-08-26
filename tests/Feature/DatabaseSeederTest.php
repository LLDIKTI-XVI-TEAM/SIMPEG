<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_expected_approval_and_mapped_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 9);
        $this->assertDatabaseHas('users', [
            'email' => 'merlina.rahman@example.com',
            'role' => 'admin_kepegawaian',
        ]);

        foreach ((array) config('services.keycloak.role_mapping', []) as $email => $role) {
            $this->assertDatabaseHas('users', [
                'email' => $email,
                'role' => $role,
            ]);
        }
    }

    public function test_database_seeder_creates_sso_role_mapped_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ((array) config('services.keycloak.role_mapping', []) as $email => $role) {
            $this->assertDatabaseHas('users', [
                'email' => $email,
                'role' => $role,
            ]);
            $this->assertDatabaseHas('employees', [
                'email' => $email,
                'status_aktif' => 'Aktif',
            ]);
        }
    }
}
