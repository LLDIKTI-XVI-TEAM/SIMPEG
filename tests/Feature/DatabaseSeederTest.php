<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_expected_demo_and_approval_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 9);
        $this->assertDatabaseHas('users', [
            'keycloak_username' => 'demo-klabat',
            'role' => 'super_admin',
        ]);
        $this->assertDatabaseHas('users', [
            'keycloak_username' => 'demo-klabat-kabag',
            'role' => 'kepala_bagian',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'merlina.rahman@example.com',
            'role' => 'admin_kepegawaian',
        ]);
    }
}
