<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoRoleSuperAdminSeeder;
use Database\Seeders\DemoSsoUserSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoRoleLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_role_user_can_login_with_matching_password(): void
    {
        $this->seed(DemoSsoUserSeeder::class);

        $response = $this->post(route('dev-login'), [
            'username' => 'demo-klabat-kepeg',
            'password' => 'demo-klabat-kepeg',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertSame('admin_kepegawaian', auth()->user()->role);
        $this->assertSame('admin_kepegawaian', session('active_role'));
    }

    public function test_demo_kabag_login_uses_current_internal_stage_one_role(): void
    {
        $this->seed(DemoSsoUserSeeder::class);

        $this->post(route('dev-login'), [
            'username' => 'demo-klabat-kabag',
            'password' => 'demo-klabat-kabag',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertSame('kepala_bagian', auth()->user()->role);
    }

    public function test_demo_pimpinan_login_is_dispatched_to_pimpinan_dashboard(): void
    {
        $this->seed([
            RbacSeeder::class,
            DemoSsoUserSeeder::class,
        ]);

        $this->post(route('dev-login'), [
            'username' => 'demo-klabat-pimpinan',
            'password' => 'demo-klabat-pimpinan',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertSame('pimpinan', auth()->user()->role);
        $this->assertSame('pimpinan', session('active_role'));
        $this->get(route('dashboard'))->assertRedirect(route('pimpinan.dashboard'));
    }

    public function test_legacy_super_admin_seeder_does_not_overwrite_configured_pimpinan_account(): void
    {
        config(['services.keycloak.test_username' => 'demo-klabat-pimpinan']);

        $this->seed(DemoSsoUserSeeder::class);
        $this->seed(DemoRoleSuperAdminSeeder::class);

        $this->assertDatabaseHas('users', [
            'keycloak_username' => 'demo-klabat',
            'role' => 'super_admin',
        ]);
        $this->assertDatabaseHas('users', [
            'keycloak_username' => 'demo-klabat-pimpinan',
            'role' => 'pimpinan',
        ]);
    }

    public function test_demo_login_rejects_wrong_password(): void
    {
        $this->seed(DemoSsoUserSeeder::class);

        $this->post(route('dev-login'), [
            'username' => 'demo-klabat-pimpinan',
            'password' => 'salah',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_demo_login_rejects_non_demo_user_even_with_valid_password(): void
    {
        User::factory()->pegawai()->create([
            'keycloak_username' => 'pegawai-biasa',
            'password' => 'pegawai-biasa',
        ]);

        $this->post(route('dev-login'), [
            'username' => 'pegawai-biasa',
            'password' => 'pegawai-biasa',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }
}
