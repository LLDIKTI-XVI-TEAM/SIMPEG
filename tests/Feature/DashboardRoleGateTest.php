<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRoleGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_user_without_role_can_login_but_cannot_open_dashboard(): void
    {
        $user = User::factory()->create(['role' => null]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertForbidden();
        $response->assertSee('Akun Anda belum memiliki role SIMPEG. Hubungi Admin.');
    }

    public function test_user_with_invalid_role_cannot_open_dashboard(): void
    {
        $user = User::factory()->create(['role' => 'role_tidak_terdaftar']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertForbidden();
        $response->assertSee('Akun Anda belum memiliki role SIMPEG. Hubungi Admin.');
    }

    public function test_user_with_valid_role_can_open_dashboard(): void
    {
        $user = User::factory()->pegawai()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
    }
}
