<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        config()->set('session.simpeg_idle_timeout', 30);
    }

    public function test_active_user_before_timeout_can_access_dashboard(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->withSession(['last_activity_at' => now()->subMinutes(29)->timestamp])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_idle_user_after_timeout_is_redirected_and_audited(): void
    {
        $user = User::factory()->adminKepegawaian()->create(['name' => 'Admin Timeout']);
        $lastActivity = now()->subMinutes(31)->timestamp;

        $response = $this->actingAs($user)
            ->withSession([
                'last_activity_at' => $lastActivity,
                'last_authenticated_user_id' => $user->id,
                'last_authenticated_user_name' => $user->name,
            ])
            ->get(route('dashboard'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('simpeg_session_timeout_message', 'Sesi Anda telah berakhir. Silakan login kembali.');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'user_name' => 'Admin Timeout',
            'event' => 'SESSION_TIMEOUT',
            'auditable_type' => 'User',
            'auditable_id' => $user->id,
        ]);
    }

    public function test_session_is_invalidated_after_timeout(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->withSession([
                'last_activity_at' => now()->subMinutes(31)->timestamp,
                'last_authenticated_user_id' => $user->id,
                'last_authenticated_user_name' => $user->name,
            ])
            ->get(route('dashboard'));

        $this->assertGuest();
    }

    public function test_json_request_after_timeout_receives_unauthenticated_response(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->withSession([
                'last_activity_at' => now()->subMinutes(31)->timestamp,
                'last_authenticated_user_id' => $user->id,
                'last_authenticated_user_name' => $user->name,
            ])
            ->getJson(route('dashboard'))
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Sesi Anda telah berakhir. Silakan login kembali.',
            ]);
    }

    public function test_timeout_message_is_rendered_once_after_login_return(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)
            ->withSession([
                'simpeg_session_timeout_message' => 'Sesi Anda telah berakhir. Silakan login kembali.',
            ])
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Sesi Anda telah berakhir. Silakan login kembali.');
        $this->assertFalse(session()->has('simpeg_session_timeout_message'));
    }

    public function test_dev_login_preserves_timeout_message_after_session_regeneration(): void
    {
        User::factory()->superAdmin()->create(['email' => 'demo@example.com']);

        $this->withSession([
            'simpeg_session_timeout_message' => 'Sesi Anda telah berakhir. Silakan login kembali.',
        ])->get(route('dev-login'))
            ->assertRedirect(route('dashboard'));

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Sesi Anda telah berakhir. Silakan login kembali.');
    }

    public function test_notification_polling_does_not_refresh_activity_timestamp(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $lastActivity = now()->subMinutes(10)->timestamp;

        $this->actingAs($user)
            ->withSession(['last_activity_at' => $lastActivity])
            ->getJson(route('api.v1.notifikasi.index'))
            ->assertOk();

        $this->assertSame($lastActivity, session('last_activity_at'));
    }

    public function test_notification_unread_count_polling_does_not_refresh_activity_timestamp(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $lastActivity = now()->subMinutes(10)->timestamp;

        $this->actingAs($user)
            ->withSession(['last_activity_at' => $lastActivity])
            ->getJson(route('api.v1.notifikasi.jumlah-belum-dibaca'))
            ->assertOk();

        $this->assertSame($lastActivity, session('last_activity_at'));
    }

    public function test_notification_polling_after_timeout_receives_unauthenticated_response(): void
    {
        $user = User::factory()->adminKepegawaian()->create(['name' => 'Admin Polling']);

        $this->actingAs($user)
            ->withSession([
                'last_activity_at' => now()->subMinutes(31)->timestamp,
                'last_authenticated_user_id' => $user->id,
                'last_authenticated_user_name' => $user->name,
            ])
            ->getJson(route('api.v1.notifikasi.index'))
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Sesi Anda telah berakhir. Silakan login kembali.',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'user_name' => 'Admin Polling',
            'event' => 'SESSION_TIMEOUT',
        ]);
    }

    public function test_notification_bell_stops_polling_after_timeout_response(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('response.status === 401', false);
        $response->assertSee('clearInterval(this.pollingTimer)', false);
    }

    public function test_audit_page_recognizes_session_timeout_event(): void
    {
        $user = User::factory()->superAdmin()->create(['name' => 'Admin Audit Timeout']);

        AuditService::logAs(
            $user->id,
            $user->name,
            'SESSION_TIMEOUT',
            'User',
            $user->id,
            ['last_activity_at' => now()->subMinutes(31)->timestamp],
            ['timed_out_at' => now()->timestamp],
        );

        $response = $this->actingAs($user)->get(route('audit-log'));

        $response->assertOk();
        $response->assertSee('<option value="SESSION_TIMEOUT">SESSION_TIMEOUT</option>', false);
        $response->assertSee('SESSION_TIMEOUT: Sesi berakhir karena idle timeout', false);
        $response->assertSee('autentikasi');
    }
}
