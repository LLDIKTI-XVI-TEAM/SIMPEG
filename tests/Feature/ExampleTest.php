<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        config([
            'services.keycloak.base_url' => 'https://sso-lldikti16.kemdiktisaintek.go.id',
            'services.keycloak.realms' => 'sso',
            'services.keycloak.realm' => 'sso',
        ]);
    }

    public function test_guest_home_redirects_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_login_redirects_to_keycloak(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(302);
        $this->assertStringStartsWith(
            'https://sso-lldikti16.kemdiktisaintek.go.id/realms/sso/protocol/openid-connect/auth',
            $response->headers->get('Location'),
        );
    }

    public function test_dev_login_route_is_removed(): void
    {
        // Seluruh login wajib melalui identitas Keycloak asli; jalur demo/dev-login dihapus.
        $response = $this->get('/dev-login');

        $response->assertNotFound();
        $this->assertGuest();
    }

    public function test_keycloak_login_redirects_to_keycloak(): void
    {
        $response = $this->get('/login/keycloak');

        $response->assertStatus(302);
        $this->assertStringStartsWith(
            'https://sso-lldikti16.kemdiktisaintek.go.id/realms/sso/protocol/openid-connect/auth',
            $response->headers->get('Location'),
        );
    }

    public function test_authenticated_dashboard_renders(): void
    {
        $user = User::factory()->pegawai()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Dashboard');
        $response->assertSee($user->email);
    }
}
