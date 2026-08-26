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

    public function test_dev_login_route_is_removed(): void
    {
        // Seluruh login wajib melalui identitas Keycloak asli; jalur demo/dev-login dihapus.
        $response = $this->get('/dev-login');

        $response->assertNotFound();
        $this->assertGuest();
    }

    public function test_legacy_set_super_admin_route_is_not_available(): void
    {
        $user = User::factory()->pimpinan()->create();

        $this->actingAs($user)
            ->get('/set-super-admin')
            ->assertNotFound();

        $this->assertSame('pimpinan', $user->fresh()->role);
    }
}
