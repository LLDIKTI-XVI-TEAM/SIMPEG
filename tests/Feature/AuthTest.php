<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_redirects_to_keycloak(): void
    {
        $response = $this->get(route('login'));

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://sso-lldikti16.kemdiktisaintek.go.id/realms/sso/protocol/openid-connect/auth',
            (string) $response->headers->get('Location'),
        );
    }

    public function test_unregistered_keycloak_account_receives_documented_forbidden_page(): void
    {
        $this->fakeKeycloakUser([
            'id' => 'kc-unregistered',
            'nickname' => 'unregistered',
            'name' => 'Pengguna Belum Terdaftar',
            'email' => null,
            'raw' => ['preferred_username' => 'unregistered'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Akun Keycloak belum terdaftar');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * Stub Socialite agar test hanya memverifikasi keputusan akses SIMPEG, bukan jaringan Keycloak.
     *
     * @param  array{id: string, nickname: string, name: string, email: string|null, raw: array<string, mixed>}  $attributes
     */
    private function fakeKeycloakUser(array $attributes): void
    {
        $user = (new SocialiteUser)->setRaw($attributes['raw'])->map([
            'id' => $attributes['id'],
            'nickname' => $attributes['nickname'],
            'name' => $attributes['name'],
            'email' => $attributes['email'],
        ]);

        $provider = new class($user)
        {
            public function __construct(private readonly SocialiteUser $user) {}

            public function user(): SocialiteUser
            {
                return $this->user;
            }
        };

        Socialite::shouldReceive('driver')
            ->once()
            ->with('keycloak')
            ->andReturn($provider);
    }
}
