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

    public function test_authenticated_dashboard_renders(): void
    {
        $user = User::factory()->pegawai()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Dashboard');
        $response->assertSee($user->email);
    }
}
