<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class KeycloakCallbackMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_keycloak_email_match_creates_local_super_admin(): void
    {
        config()->set('services.keycloak.employee_match_field', 'email');

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Budi Santoso',
            'email' => 'budi@example.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-pegawai-1',
            'nickname' => 'budi',
            'name' => 'Budi SSO',
            'email' => 'budi@example.com',
            'raw' => ['email' => 'budi@example.com', 'email_verified' => true, 'preferred_username' => 'budi'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'budi@example.com',
            'keycloak_id' => 'kc-pegawai-1',
            'keycloak_username' => 'budi',
            'employee_id' => $employee->id,
            'role' => 'super_admin',
        ]);
    }

    public function test_new_sso_matched_user_after_bootstrap_gets_null_role(): void
    {
        User::factory()->superAdmin()->create();

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Budi Santoso',
            'email' => 'budi-null-role@example.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-pegawai-null-role',
            'nickname' => 'budi-null-role',
            'name' => 'Budi SSO',
            'email' => 'budi-null-role@example.com',
            'raw' => ['email' => 'budi-null-role@example.com', 'email_verified' => true, 'preferred_username' => 'budi-null-role'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'budi-null-role@example.com',
            'keycloak_id' => 'kc-pegawai-null-role',
            'employee_id' => $employee->id,
            'role' => null,
        ]);
    }

    public function test_employee_email_matching_is_case_insensitive(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Budi Santoso',
            'email' => 'Budi@Example.COM',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-case',
            'nickname' => 'budi',
            'name' => 'Budi SSO',
            'email' => 'budi@example.com',
            'raw' => ['email' => 'budi@example.com', 'email_verified' => true, 'preferred_username' => 'budi'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'email' => 'budi@example.com',
            'employee_id' => $employee->id,
        ]);
    }

    public function test_existing_user_email_matching_is_case_insensitive(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Budi Santoso',
            'email' => 'budi@example.com',
        ]);
        $user = User::factory()->pegawai()->create([
            'email' => 'Budi@Example.COM',
            'employee_id' => null,
            'keycloak_id' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-user-case',
            'nickname' => 'budi',
            'name' => 'Budi SSO',
            'email' => 'budi@example.com',
            'raw' => ['email' => 'budi@example.com', 'email_verified' => true, 'preferred_username' => 'budi'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'Budi@Example.COM',
            'keycloak_id' => 'kc-user-case',
            'employee_id' => $employee->id,
        ]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_privileged_user_with_employee_email_is_not_auto_bound(): void
    {
        Employee::factory()->create(['email' => 'admin@example.com']);
        User::factory()->superAdmin()->create([
            'email' => 'admin@example.com',
            'employee_id' => null,
            'keycloak_id' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-admin-email',
            'nickname' => 'admin-email',
            'name' => 'Admin Email',
            'email' => 'admin@example.com',
            'raw' => ['email' => 'admin@example.com', 'email_verified' => true, 'preferred_username' => 'admin-email'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Akun SIMPEG perlu ditautkan manual oleh admin.');
        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.com',
            'role' => 'super_admin',
            'employee_id' => null,
            'keycloak_id' => null,
        ]);
    }

    public function test_existing_keycloak_id_logs_in_without_rebinding_employee(): void
    {
        $firstEmployee = Employee::factory()->create(['email' => 'lama@example.com']);
        $secondEmployee = Employee::factory()->create(['email' => 'baru@example.com']);
        $user = User::factory()->create([
            'email' => 'lama@example.com',
            'keycloak_id' => 'kc-existing',
            'keycloak_username' => 'pegawai-lama',
            'employee_id' => $firstEmployee->id,
            'role' => 'admin_kepegawaian',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-existing',
            'nickname' => 'pegawai-baru',
            'name' => 'Nama Baru',
            'email' => 'baru@example.com',
            'raw' => ['email' => 'baru@example.com', 'email_verified' => true, 'preferred_username' => 'pegawai-baru'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'lama@example.com',
            'employee_id' => $firstEmployee->id,
            'role' => 'admin_kepegawaian',
        ]);
        $this->assertDatabaseMissing('users', ['employee_id' => $secondEmployee->id]);
    }

    public function test_missing_email_without_whitelist_is_denied_and_does_not_create_user(): void
    {
        $this->fakeKeycloakUser([
            'id' => 'kc-outsider',
            'nickname' => 'outsider',
            'name' => 'Outsider',
            'email' => null,
            'raw' => ['preferred_username' => 'outsider'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Akun Keycloak belum terdaftar');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unverified_email_does_not_match_employee(): void
    {
        Employee::factory()->create(['email' => 'budi@example.com']);

        $this->fakeKeycloakUser([
            'id' => 'kc-unverified-email',
            'nickname' => 'budi',
            'name' => 'Budi SSO',
            'email' => 'budi@example.com',
            'raw' => ['email' => 'budi@example.com', 'email_verified' => false, 'preferred_username' => 'budi'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Akun Keycloak belum terdaftar');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_preferred_username_does_not_match_employee_email(): void
    {
        Employee::factory()->create(['email' => 'budi@example.com']);

        $this->fakeKeycloakUser([
            'id' => 'kc-preferred-email',
            'nickname' => 'budi@example.com',
            'name' => 'Budi SSO',
            'email' => null,
            'raw' => ['preferred_username' => 'budi@example.com'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Akun Keycloak belum terdaftar');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_email_with_whitelisted_dev_user_can_login(): void
    {
        $user = User::factory()->superAdmin()->create([
            'email' => 'demo-klabat@dev.local',
            'keycloak_username' => 'demo-klabat',
            'employee_id' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-demo',
            'nickname' => 'demo-klabat',
            'name' => 'Demo Klabat',
            'email' => null,
            'raw' => ['preferred_username' => 'demo-klabat'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'keycloak_id' => 'kc-demo',
            'keycloak_username' => 'demo-klabat',
            'employee_id' => null,
            'role' => 'super_admin',
        ]);
    }

    public function test_missing_email_with_non_allowlisted_local_user_is_denied(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'demo-role@dev.local',
            'keycloak_username' => 'demo-role',
            'employee_id' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-demo-role',
            'nickname' => 'demo-role',
            'name' => 'Demo Role',
            'email' => null,
            'raw' => ['preferred_username' => 'demo-role'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Akun Keycloak belum terdaftar');
        $this->assertGuest();
    }

    public function test_duplicate_employee_match_is_denied(): void
    {
        Employee::factory()->create(['email' => 'duplikat@example.com']);
        Employee::factory()->create(['email' => 'duplikat@example.com']);

        $this->fakeKeycloakUser([
            'id' => 'kc-duplicate',
            'nickname' => 'duplikat',
            'name' => 'Duplikat',
            'email' => 'duplikat@example.com',
            'raw' => ['email' => 'duplikat@example.com', 'email_verified' => true, 'preferred_username' => 'duplikat'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Akun Keycloak belum terdaftar');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_employee_match_config_is_denied(): void
    {
        config()->set('services.keycloak.employee_match_field', 'role');

        Employee::factory()->create(['email' => 'budi@example.com']);

        $this->fakeKeycloakUser([
            'id' => 'kc-invalid-config',
            'nickname' => 'budi',
            'name' => 'Budi',
            'email' => 'budi@example.com',
            'raw' => ['email' => 'budi@example.com', 'email_verified' => true, 'preferred_username' => 'budi'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Konfigurasi pencocokan akun SSO belum valid.');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_keycloak_role_claim_does_not_change_simpeg_role(): void
    {
        config()->set('services.keycloak.dev_usernames', ['demo-role']);

        $user = User::factory()->pegawai()->create([
            'email' => 'demo-role@dev.local',
            'keycloak_username' => 'demo-role',
            'employee_id' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-role',
            'nickname' => 'demo-role',
            'name' => 'Demo Role',
            'email' => null,
            'raw' => [
                'preferred_username' => 'demo-role',
                'realm_access' => ['roles' => ['super_admin']],
            ],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'pegawai',
        ]);
    }

    public function test_keycloak_role_claim_does_not_assign_role_to_new_non_bootstrap_user(): void
    {
        User::factory()->superAdmin()->create();

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Siti Aminah',
            'email' => 'siti@example.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-siti',
            'nickname' => 'siti',
            'name' => 'Siti SSO',
            'email' => 'siti@example.com',
            'raw' => [
                'email' => 'siti@example.com',
                'email_verified' => true,
                'preferred_username' => 'siti',
                'realm_access' => ['roles' => ['super_admin']],
            ],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'email' => 'siti@example.com',
            'employee_id' => $employee->id,
            'role' => null,
        ]);
    }

    public function test_database_seeder_does_not_create_generic_user_that_consumes_sso_bootstrap(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
    }

    public function test_database_seeder_creates_all_configured_demo_role_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expectedUsers = [
            'demo-klabat' => 'super_admin',
            'demo-klabat-kepeg' => 'admin_kepegawaian',
            'demo-klabat-kabag' => 'kepala_bagian',
            'demo-klabat-pimpinan' => 'pimpinan',
            'demo-klabat-pegawai' => 'pegawai',
        ];

        foreach ($expectedUsers as $username => $role) {
            $user = User::where('keycloak_username', $username)->first();

            $this->assertNotNull($user, "Demo user {$username} should exist.");
            $this->assertSame($role, $user->role);
            $this->assertTrue(Hash::check($username, $user->password), "Demo user {$username} should use matching password.");
        }
    }

    public function test_no_keycloak_local_fallback_user_is_created(): void
    {
        $this->fakeKeycloakUser([
            'id' => 'kc-no-email',
            'nickname' => 'tanpa-email',
            'name' => 'Tanpa Email',
            'email' => null,
            'raw' => ['preferred_username' => 'tanpa-email'],
        ]);

        $this->get('/auth/keycloak/callback');

        $this->assertDatabaseMissing('users', [
            'email' => 'tanpa-email@keycloak.local',
        ]);
    }

    public function test_employee_id_is_unique_for_user_mapping(): void
    {
        $employee = Employee::factory()->create();
        User::factory()->create(['employee_id' => $employee->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['employee_id' => $employee->id]);
    }

    /**
     * Stub Socialite supaya test fokus ke keputusan mapping SIMPEG, bukan jaringan Keycloak.
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
