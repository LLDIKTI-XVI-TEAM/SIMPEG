<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** K-MTG-02 (addendum 15 Agu 2026): user baru ter-map valid setelah bootstrap langsung mendapat role pegawai. */
    public function test_new_sso_matched_user_after_bootstrap_defaults_to_pegawai_role(): void
    {
        User::factory()->superAdmin()->create();

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Budi Santoso',
            'email' => 'budi-mapped@example.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-pegawai-baru',
            'nickname' => 'budi-baru',
            'name' => 'Budi SSO',
            'email' => 'budi-mapped@example.com',
            'raw' => ['email' => 'budi-mapped@example.com', 'email_verified' => true, 'preferred_username' => 'budi-baru'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'budi-mapped@example.com',
            'keycloak_id' => 'kc-pegawai-baru',
            'employee_id' => $employee->id,
            'role' => 'pegawai',
        ]);
    }

    /** K-MTG-02: user lama dengan role kosong (pra-addendum) + mapping valid diinisialisasi menjadi pegawai saat login. */
    public function test_existing_mapped_user_with_blank_role_is_initialized_to_pegawai_on_login(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Siti Lama',
            'email' => 'siti-lama@example.com',
        ]);
        $user = User::factory()->create([
            'email' => 'siti-lama@example.com',
            'keycloak_id' => 'kc-siti-lama',
            'employee_id' => $employee->id,
            'role' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-siti-lama',
            'nickname' => 'siti-lama',
            'name' => 'Siti Lama',
            'email' => 'siti-lama@example.com',
            'raw' => ['email' => 'siti-lama@example.com', 'email_verified' => true, 'preferred_username' => 'siti-lama'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'employee_id' => $employee->id,
            'role' => 'pegawai',
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
        $this->assertDatabaseHas('employees', [
            'id' => $firstEmployee->id,
            'email_pribadi' => 'lama@example.com',
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
        $this->withoutVite();

        $firstEmployee = Employee::factory()->create(['email_pribadi' => 'kanonis-satu@example.com']);
        $secondEmployee = Employee::factory()->create(['email_pribadi' => 'kanonis-dua@example.com']);

        // Fixture legacy boleh ambigu di kolom email lama, sedangkan identitas kanonis tetap unik.
        DB::table('employees')
            ->whereIn('id', [$firstEmployee->id, $secondEmployee->id])
            ->update(['email' => 'duplikat@example.com']);

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

    /** K-MTG-02: claim role Keycloak tidak pernah menjadi sumber RBAC; user baru non-bootstrap tetap mendapat default pegawai. */
    public function test_keycloak_role_claim_does_not_override_pegawai_default_for_new_mapped_user(): void
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
            'role' => 'pegawai',
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

    // ─── Email Pribadi Sync Tests ─────────────────────────────────────────────

    public function test_login_keycloak_syncs_verified_email_to_employee_email_pribadi(): void
    {
        $employee = Employee::factory()->create([
            'email_pribadi' => 'lama@example.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-sync-email',
            'nickname' => 'budi-sync',
            'name' => 'Budi Sync',
            'email' => 'baru@example.com',
            'raw' => ['email' => 'baru@example.com', 'email_verified' => true, 'preferred_username' => 'budi-sync'],
        ]);

        // Buat user yang sudah terhubung ke employee via keycloak_id
        User::factory()->create([
            'email' => 'lama@example.com',
            'keycloak_id' => 'kc-sync-email',
            'employee_id' => $employee->id,
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email_pribadi' => 'baru@example.com',
        ]);
    }

    public function test_first_time_login_also_syncs_verified_email_to_employee_email_pribadi(): void
    {
        $employee = Employee::factory()->create([
            'email_pribadi' => 'siti@example.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-siti-sync',
            'nickname' => 'siti-sync',
            'name' => 'Siti Sync',
            'email' => 'siti@example.com',
            'raw' => ['email' => 'siti@example.com', 'email_verified' => true, 'preferred_username' => 'siti-sync'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        // email_pribadi tidak berubah karena sudah sama
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email_pribadi' => 'siti@example.com',
        ]);
    }

    public function test_email_pribadi_is_not_updated_when_keycloak_email_matches_existing(): void
    {
        $employee = Employee::factory()->create([
            'email_pribadi' => 'sama@example.com',
        ]);

        $user = User::factory()->create([
            'email' => 'sama@example.com',
            'keycloak_id' => 'kc-sama',
            'employee_id' => $employee->id,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-sama',
            'nickname' => 'sama',
            'name' => 'Sama',
            'email' => 'sama@example.com',
            'raw' => ['email' => 'sama@example.com', 'email_verified' => true, 'preferred_username' => 'sama'],
        ]);

        $before = $employee->fresh()->updated_at;

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        // updated_at employees tidak berubah karena saveQuietly tidak dipanggil
        $this->assertEquals($before, $employee->fresh()->updated_at);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email_pribadi' => 'sama@example.com',
        ]);
    }

    public function test_unverified_keycloak_email_does_not_update_employee_email_pribadi(): void
    {
        $employee = Employee::factory()->create([
            'email_pribadi' => 'asli@example.com',
        ]);

        $user = User::factory()->create([
            'email' => 'asli@example.com',
            'keycloak_id' => 'kc-unverified-sync',
            'employee_id' => $employee->id,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-unverified-sync',
            'nickname' => 'unverified-sync',
            'name' => 'Unverified',
            'email' => 'palsu@example.com',
            'raw' => ['email' => 'palsu@example.com', 'email_verified' => false, 'preferred_username' => 'unverified-sync'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        // email_pribadi tidak berubah karena email tidak terverifikasi
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email_pribadi' => 'asli@example.com',
        ]);
    }

    /** Tindak lanjut review PR #199: mutasi email canonical dicatat ke audit dengan old/new value + sumber SSO. */
    public function test_email_sync_writes_audit_with_old_and_new_values(): void
    {
        $employee = Employee::factory()->create([
            'email_pribadi' => 'lama-audit@example.com',
        ]);
        User::factory()->create([
            'email' => 'lama-audit@example.com',
            'keycloak_id' => 'kc-audit-sync',
            'employee_id' => $employee->id,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-audit-sync',
            'nickname' => 'audit-sync',
            'name' => 'Audit Sync',
            'email' => 'baru-audit@example.com',
            'raw' => ['email' => 'baru-audit@example.com', 'email_verified' => true, 'preferred_username' => 'audit-sync'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email_pribadi' => 'baru-audit@example.com',
        ]);

        $audit = AuditLog::query()->where('event', 'EMAIL_SYNCED')->where('auditable_id', $employee->id)->sole();
        $this->assertSame(['email_pribadi' => 'lama-audit@example.com'], $audit->old_values);
        $this->assertSame(['email_pribadi' => 'baru-audit@example.com', 'source' => 'keycloak'], $audit->new_values);
        $this->assertNotNull($audit->user_id);
    }

    /** Sync tidak boleh menimpa email yang dicadangkan pegawai nonaktif; login tetap berhasil dan konflik dicatat. */
    public function test_login_does_not_crash_when_keycloak_email_belongs_to_trashed_employee(): void
    {
        $trashedOwner = Employee::factory()->create(['email_pribadi' => 'dinas@example.com']);
        $trashedOwner->delete();

        $employee = Employee::factory()->create(['email_pribadi' => 'aktif@example.com']);
        User::factory()->create([
            'email' => 'aktif@example.com',
            'keycloak_id' => 'kc-trashed-owner',
            'employee_id' => $employee->id,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-trashed-owner',
            'nickname' => 'aktif',
            'name' => 'Aktif',
            'email' => 'dinas@example.com',
            'raw' => ['email' => 'dinas@example.com', 'email_verified' => true, 'preferred_username' => 'aktif'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        // Email tetap di tangan pegawai nonaktif; user login tanpa exception.
        $this->assertDatabaseHas('employees', [
            'id' => $trashedOwner->id,
            'email_pribadi' => 'dinas@example.com',
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email_pribadi' => 'aktif@example.com',
        ]);

        // Follow-up review PR #199: konflik yang terdeteksi sebelum penulisan (ownership check)
        // juga dicatat ke audit agar Admin punya jejak remediasi.
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'EMAIL_CONFLICT',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    /** Follow-up review PR #199: email aktif milik pegawai lain juga menghasilkan EMAIL_CONFLICT yang terlihat. */
    public function test_login_records_conflict_when_keycloak_email_owned_by_active_employee(): void
    {
        $owner = Employee::factory()->create(['email_pribadi' => 'dinas@example.com']);
        $employee = Employee::factory()->create(['email_pribadi' => 'aktif@example.com']);
        User::factory()->create([
            'email' => 'aktif@example.com',
            'keycloak_id' => 'kc-active-owner',
            'employee_id' => $employee->id,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-active-owner',
            'nickname' => 'aktif',
            'name' => 'Aktif',
            'email' => 'dinas@example.com',
            'raw' => ['email' => 'dinas@example.com', 'email_verified' => true, 'preferred_username' => 'aktif'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('employees', [
            'id' => $owner->id,
            'email_pribadi' => 'dinas@example.com',
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email_pribadi' => 'aktif@example.com',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'EMAIL_CONFLICT',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);

        $conflict = AuditLog::query()->where('event', 'EMAIL_CONFLICT')->where('auditable_id', $employee->id)->sole();
        $this->assertSame('dinas@example.com', $conflict->new_values['attempted_email'] ?? null);
    }

    /** Follow-up review PR #199: nilai lama NULL pada email_pribadi direkam sebagai null, bukan string kosong. */
    public function test_email_sync_preserves_null_previous_email_in_audit(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Legacy Null',
            'email' => 'legacy-null@example.com',
        ]);

        // Setter model selalu menyinkronkan email ↔ email_pribadi, jadi data legacy dengan
        // email_pribadi NULL hanya bisa hadir lewat baris lama: tulis ulang via query builder.
        DB::table('employees')->where('id', $employee->id)->update(['email_pribadi' => null]);

        User::factory()->create([
            'email' => 'legacy-null@example.com',
            'keycloak_id' => 'kc-legacy-null',
            'employee_id' => $employee->id,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-legacy-null',
            'nickname' => 'legacy-null',
            'name' => 'Legacy Null',
            'email' => 'kanonik@example.com',
            'raw' => ['email' => 'kanonik@example.com', 'email_verified' => true, 'preferred_username' => 'legacy-null'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email_pribadi' => 'kanonik@example.com',
        ]);

        $audit = AuditLog::query()->where('event', 'EMAIL_SYNCED')->where('auditable_id', $employee->id)->sole();
        $this->assertSame(['email_pribadi' => null], $audit->old_values);
        $this->assertSame(['email_pribadi' => 'kanonik@example.com', 'source' => 'keycloak'], $audit->new_values);
    }

    /** User lama milik pegawai yang dinonaktifkan tetap sync email-nya ke record trashed tersebut. */
    public function test_existing_user_mapped_to_trashed_employee_still_syncs_email(): void
    {
        $trashedEmployee = Employee::factory()->create(['email_pribadi' => 'lama@example.com']);
        $trashedEmployee->delete();

        User::factory()->create([
            'email' => 'lama@example.com',
            'keycloak_id' => 'kc-trashed-sync',
            'employee_id' => $trashedEmployee->id,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-trashed-sync',
            'nickname' => 'trashed',
            'name' => 'Trashed',
            'email' => 'baru@example.com',
            'raw' => ['email' => 'baru@example.com', 'email_verified' => true, 'preferred_username' => 'trashed'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('employees', [
            'id' => $trashedEmployee->id,
            'email_pribadi' => 'baru@example.com',
        ]);
    }

    /**
     * Dua callback untuk pegawai berbeda yang membawa email baru yang sama dapat melewati
     * pemeriksaan kepemilikan sebelum salah satu transaksi menulis. Penulisan kedua melanggar
     * index unik case-insensitive employees_email_pribadi_unique; login tetap harus berhasil
     * tanpa mengubah email, seperti halnya konflik yang sudah tersimpan.
     */
    public function test_login_survives_unique_race_on_email_pribadi_sync(): void
    {
        $employee = Employee::factory()->create(['email_pribadi' => 'aktif@example.com']);
        User::factory()->create([
            'email' => 'aktif@example.com',
            'keycloak_id' => 'kc-race',
            'employee_id' => $employee->id,
        ]);

        // Simulasikan penulisan kedua yang ditolak index unik lewat trigger database,
        // karena saveQuietly melewati event model sehingga tidak bisa disimulasikan via listener.
        // Trigger dibuat sesuai driver: SQLite memakai RAISE(ABORT) bawaan, sedangkan PostgreSQL
        // memakai fungsi PL/pgSQL yang menaikkan SQLSTATE 23505 dengan pesan constraint yang sama.
        $this->createEmailPribadiRejectTrigger();

        try {
            $this->fakeKeycloakUser([
                'id' => 'kc-race',
                'nickname' => 'race',
                'name' => 'Race',
                'email' => 'baru@example.com',
                'raw' => ['email' => 'baru@example.com', 'email_verified' => true, 'preferred_username' => 'race'],
            ]);

            // Isolasi savepoint kini dilakukan di kode produksi (DB::transaction bersarang →
            // savepoint di PostgreSQL), sehingga transaksi test tetap sehat tanpa bantuan test ini.
            $response = $this->get('/auth/keycloak/callback');

            $response->assertRedirect(route('dashboard'));
            $this->assertAuthenticated();
        } finally {
            $this->dropEmailPribadiRejectTrigger();
        }

        // Email tetap di tangan pegawai semula; login tidak terganggu benturan unik.
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email_pribadi' => 'aktif@example.com',
        ]);

        // Tindak lanjut review PR #199: benturan unik tidak lagi ditelan diam-diam,
        // melainkan tercatat di audit agar Admin melihat konflik email canonical.
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'EMAIL_CONFLICT',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    private function createEmailPribadiRejectTrigger(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_email_pribadi_race_fn() RETURNS trigger
                LANGUAGE plpgsql AS $$
                BEGIN
                    IF NEW.email_pribadi = 'baru@example.com' THEN
                        RAISE EXCEPTION 'duplicate key value violates unique constraint "employees_email_pribadi_unique"'
                            USING ERRCODE = '23505';
                    END IF;
                    RETURN NEW;
                END;
                $$;
                CREATE TRIGGER reject_email_pribadi_race
                BEFORE UPDATE OF email_pribadi ON employees
                FOR EACH ROW EXECUTE FUNCTION reject_email_pribadi_race_fn();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_email_pribadi_race
            BEFORE UPDATE OF email_pribadi ON employees
            WHEN NEW.email_pribadi = 'baru@example.com'
            BEGIN
                SELECT RAISE(ABORT, 'UNIQUE constraint failed: index ''employees_email_pribadi_unique''');
            END;
            SQL);
    }

    private function dropEmailPribadiRejectTrigger(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_email_pribadi_race ON employees');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_email_pribadi_race_fn()');

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS reject_email_pribadi_race');
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
