<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class KeycloakLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_logout_clears_session_redirects_to_keycloak_and_writes_audit(): void
    {
        $user = User::factory()->pegawai()->create([
            'name' => 'Budi Santoso',
        ]);
        $logoutUrl = 'https://sso.example.test/realms/sso/protocol/openid-connect/logout?client_id=simpeg';

        $this->fakeKeycloakLogoutUrl($logoutUrl);

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect($logoutUrl);
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'user_name' => 'Budi Santoso',
            'event' => 'LOGOUT',
            'auditable_type' => 'User',
            'auditable_id' => $user->id,
        ]);
    }

    public function test_logout_audit_is_written_before_session_user_is_cleared(): void
    {
        $user = User::factory()->adminKepegawaian()->create([
            'name' => 'Admin Kepegawaian',
        ]);
        $logoutUrl = 'https://sso.example.test/logout';

        $this->fakeKeycloakLogoutUrl($logoutUrl);

        $this->actingAs($user)->post('/logout');

        $audit = AuditLog::where('event', 'LOGOUT')->firstOrFail();

        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('Admin Kepegawaian', $audit->user_name);
    }

    private function fakeKeycloakLogoutUrl(string $logoutUrl): void
    {
        $provider = new class($logoutUrl) {
            public function __construct(private readonly string $logoutUrl) {}

            public function getLogoutUrl(?string $redirectUri = null, ?string $clientId = null): string
            {
                return $this->logoutUrl;
            }
        };

        Socialite::shouldReceive('driver')
            ->once()
            ->with('keycloak')
            ->andReturn($provider);
    }
}
