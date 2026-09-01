<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SsoRoleMappedAccountSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** User baru ter-map valid setelah bootstrap langsung mendapat role pegawai, dan inisialisasi role diaudit. */
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

        // Inisialisasi role adalah mutasi penting: tercatat di audit dengan old role null.
        $audit = AuditLog::query()->where('event', 'UPDATE')->where('auditable_type', 'User')->sole();
        $this->assertSame(['role' => null], $audit->old_values);
        $this->assertSame('pegawai', $audit->new_values['role'] ?? null);
        $this->assertSame($employee->id, $audit->new_values['employee_id'] ?? null);
        $this->assertSame('sso_bootstrap', $audit->new_values['source'] ?? null);
    }

    /** Login pertama dengan email ter-map tetap di-bootstrap super_admin (keputusan stakeholder), BUKAN role dari email. */
    public function test_first_login_with_mapped_email_still_bootstraps_super_admin_without_email_authorization(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Kabag SSO',
            'email' => 'kabag@example.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-kabag',
            'nickname' => 'kabag',
            'name' => 'Kabag SSO',
            'email' => 'kabag@example.com',
            'raw' => ['email' => 'kabag@example.com', 'email_verified' => true, 'preferred_username' => 'kabag'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'email' => 'kabag@example.com',
            'keycloak_id' => 'kc-kabag',
            'employee_id' => $employee->id,
            // Akun pertama sistem → super_admin via bootstrap internal, bukan karena email ter-map.
            'role' => 'super_admin',
        ]);
    }

    /** preferred_username Keycloak yang sudah dipakai user lain tidak menggagalkan login; identitas kanonis adalah keycloak_id. */
    public function test_keycloak_username_collision_does_not_break_login(): void
    {
        // User demo lokal memegang keycloak_username 'demo-klabat' (constraint unik).
        User::factory()->create([
            'email' => 'demo-klabat@example.test',
            'keycloak_username' => 'demo-klabat',
            'role' => 'super_admin',
        ]);

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Superadmin LLDIKTI16',
            'email' => 'dayen.sso@example.test',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-dayensite',
            'nickname' => 'demo-klabat', // benturan: username sama dengan milik user demo
            'name' => 'Superadmin LLDIKTI16',
            'email' => 'dayen.sso@example.test',
            'raw' => ['email' => 'dayen.sso@example.test', 'email_verified' => true, 'preferred_username' => 'demo-klabat'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        // Login sukses: keycloak_id terisi, role internal default (bootstrap super_admin
        // sudah dikonsumsi akun demo, mapping tidak pernah meng-elevate), dan
        // keycloak_username yang bentrok TIDAK menimpa milik user demo.
        $mappedUser = User::where('email', 'dayen.sso@example.test')->first();
        $this->assertSame('kc-dayensite', $mappedUser->keycloak_id);
        $this->assertSame('pegawai', $mappedUser->role);
        $this->assertNotSame('demo-klabat', $mappedUser->keycloak_username);

        // Pemilik asli username tidak berubah.
        $this->assertDatabaseHas('users', [
            'email' => 'demo-klabat@example.test',
            'keycloak_username' => 'demo-klabat',
        ]);
    }

    /** User baru ter-map valid TETAP pegawai: email SSO tidak pernah memberi elevated role (K-MTG-02). */
    public function test_new_mapped_login_after_bootstrap_gets_pegawai_role_not_elevated(): void
    {
        User::factory()->superAdmin()->create();

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Kepeg SSO',
            'email' => 'kepeg@example.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-kepeg',
            'nickname' => 'kepeg',
            'name' => 'Kepeg SSO',
            'email' => 'kepeg@example.com',
            'raw' => ['email' => 'kepeg@example.com', 'email_verified' => true, 'preferred_username' => 'kepeg'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'email' => 'kepeg@example.com',
            'keycloak_id' => 'kc-kepeg',
            'employee_id' => $employee->id,
            'role' => 'pegawai',
        ]);

        $audit = AuditLog::query()->where('event', 'UPDATE')->where('auditable_type', 'User')->sole();
        $this->assertSame(['role' => null], $audit->old_values);
        $this->assertSame('pegawai', $audit->new_values['role'] ?? null);
        $this->assertSame('sso_bootstrap', $audit->new_values['source'] ?? null);
    }

    /** Role internal yang sudah ditetapkan tidak pernah dioverwrite oleh role_mapping email SSO. */
    public function test_existing_role_is_never_overwritten_by_email_mapping(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pimpinan Lama',
            'email' => 'pimpinan@example.com',
        ]);
        $user = User::factory()->create([
            'email' => 'pimpinan@example.com',
            'keycloak_id' => 'kc-pimpinan-lama',
            'employee_id' => $employee->id,
            'role' => 'pegawai',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-pimpinan-lama',
            'nickname' => 'pimpinan-lama',
            'name' => 'Pimpinan Lama',
            'email' => 'pimpinan@example.com',
            'raw' => ['email' => 'pimpinan@example.com', 'email_verified' => true, 'preferred_username' => 'pimpinan-lama'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'pegawai',
        ]);
    }

    /** User lama ber-role kosong dengan email ter-map diinisialisasi ke pegawai (bukan role pemetaan); inisialisasi diaudit. */
    public function test_existing_mapped_user_with_blank_role_gets_pegawai_role_on_login(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Dayen Lama',
            'email' => 'dayen@example.com',
        ]);
        $user = User::factory()->create([
            'email' => 'dayen@example.com',
            'keycloak_id' => 'kc-dayen-lama',
            'employee_id' => $employee->id,
            'role' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-dayen-lama',
            'nickname' => 'dayen-lama',
            'name' => 'Dayen Lama',
            'email' => 'dayen@example.com',
            'raw' => ['email' => 'dayen@example.com', 'email_verified' => true, 'preferred_username' => 'dayen-lama'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'pegawai',
        ]);

        $audit = AuditLog::query()->where('event', 'UPDATE')->where('auditable_type', 'User')->sole();
        $this->assertSame(['role' => null], $audit->old_values);
        $this->assertSame('pegawai', $audit->new_values['role'] ?? null);
        $this->assertSame('sso_bootstrap', $audit->new_values['source'] ?? null);
    }

    /**
     * Role dari Admin yang committed setelah resolver membaca snapshot lama tidak
     * boleh ditimpa default pegawai ketika callback mengambil lock sebelum save.
     */
    public function test_callback_preserves_role_committed_by_admin_before_locked_save(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Role Terkunci',
            'email' => 'role-terkunci@example.com',
        ]);
        $user = User::factory()->create([
            'email' => 'role-terkunci@example.com',
            'employee_id' => $employee->id,
            'keycloak_id' => null,
            'role' => null,
        ]);

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected, $user): void {
            if ($injected
                || ! str_contains((string) $query->sql, 'from "users" where "employee_id" =')
                || ! str_contains((string) $query->sql, 'for update')) {
                return;
            }

            $injected = true;
            DB::table('users')->where('id', $user->id)->update(['role' => 'admin_kepegawaian']);
        });

        $this->fakeKeycloakUser([
            'id' => 'kc-role-terkunci',
            'nickname' => 'role-terkunci',
            'name' => 'Role Terkunci',
            'email' => 'role-terkunci@example.com',
            'raw' => ['email' => 'role-terkunci@example.com', 'email_verified' => true, 'preferred_username' => 'role-terkunci'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        $this->assertSame('admin_kepegawaian', $user->refresh()->role);
    }

    /** User lama ber-role kosong dengan mapping valid diinisialisasi menjadi pegawai saat login; inisialisasi diaudit. */
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

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'User',
            'auditable_id' => $user->id,
        ]);
        $audit = AuditLog::query()->where('event', 'UPDATE')->where('auditable_id', $user->id)->sole();
        $this->assertSame(['role' => null], $audit->old_values);
        $this->assertSame('pegawai', $audit->new_values['role'] ?? null);
    }

    /** Role string kosong diperlakukan sama seperti role null: diinisialisasi menjadi pegawai dan diaudit. */
    public function test_existing_mapped_user_with_empty_role_is_initialized_to_pegawai_on_login(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Kadir Kosong',
            'email' => 'kadir-empty@example.com',
        ]);
        $user = User::factory()->create([
            'email' => 'kadir-empty@example.com',
            'keycloak_id' => 'kc-empty-role',
            'employee_id' => $employee->id,
            'role' => '',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-empty-role',
            'nickname' => 'kadir-empty',
            'name' => 'Kadir Kosong',
            'email' => 'kadir-empty@example.com',
            'raw' => ['email' => 'kadir-empty@example.com', 'email_verified' => true, 'preferred_username' => 'kadir-empty'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'employee_id' => $employee->id,
            'role' => 'pegawai',
        ]);

        $audit = AuditLog::query()->where('event', 'UPDATE')->where('auditable_id', $user->id)->sole();
        $this->assertSame(['role' => ''], $audit->old_values);
        $this->assertSame('pegawai', $audit->new_values['role'] ?? null);
    }

    /** Pegawai yang sudah dinonaktifkan (kelompok status bukan Aktif) tidak boleh dipetakan menjadi akun SSO baru. */
    public function test_non_active_employee_cannot_match_keycloak_login(): void
    {
        Employee::factory()->create([
            'nama_lengkap' => 'Nonaktif',
            'email' => 'nonaktif@example.com',
            'status_aktif' => 'Pensiun',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-nonaktif',
            'nickname' => 'nonaktif',
            'name' => 'Nonaktif',
            'email' => 'nonaktif@example.com',
            'raw' => ['email' => 'nonaktif@example.com', 'email_verified' => true, 'preferred_username' => 'nonaktif'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Akun Keycloak belum terdaftar');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    /** Akun yang sudah ada tetap tidak mendapat role baru apabila pegawai terkait sudah dinonaktifkan. */
    public function test_role_not_initialized_for_account_of_deactivated_employee(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Nonaktif Terpeta',
            'email' => 'softdel-account@example.com',
            'status_aktif' => 'Non-Aktif',
            'status_pegawai_id' => RefStatusPegawai::query()->where('kode', 'NONAKTIF')->value('id'),
        ]);
        $user = User::factory()->create([
            'email' => 'softdel-account@example.com',
            'keycloak_id' => 'kc-softdel-account',
            'employee_id' => $employee->id,
            'role' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-softdel-account',
            'nickname' => 'softdel-account',
            'name' => 'Nonaktif Terpeta',
            'email' => 'softdel-account@example.com',
            'raw' => ['email' => 'softdel-account@example.com', 'email_verified' => true, 'preferred_username' => 'softdel-account'],
        ]);

        $this->get('/auth/keycloak/callback');

        // Role tetap kosong (akses yang dicabut lewat deaktivasi tidak pulih) dan tidak ada
        // audit inisialisasi role untuk akun ini.
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'employee_id' => $employee->id,
            'role' => null,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'User',
            'auditable_id' => $user->id,
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

    /**
     * Fallback email bukan identity kanonis. Variasi kapitalisasi legacy yang
     * menunjuk lebih dari satu user harus ditolak, bukan dipilih secara arbitrer.
     */
    public function test_ambiguous_case_insensitive_user_email_fallback_is_rejected(): void
    {
        Employee::factory()->create([
            'nama_lengkap' => 'Budi Santoso',
            'email' => 'budi@example.com',
        ]);
        $first = User::factory()->pegawai()->create([
            'email' => 'Budi@Example.COM',
            'employee_id' => null,
            'keycloak_id' => null,
        ]);
        $second = User::factory()->pegawai()->create([
            'email' => 'budi@example.com',
            'employee_id' => null,
            'keycloak_id' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-user-ambiguous',
            'nickname' => 'budi',
            'name' => 'Budi SSO',
            'email' => 'budi@example.com',
            'raw' => ['email' => 'budi@example.com', 'email_verified' => true, 'preferred_username' => 'budi'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk()->assertSee('Konflik identitas akun SIMPEG terdeteksi.');
        $this->assertGuest();
        $this->assertDatabaseHas('users', ['id' => $first->id, 'keycloak_id' => null]);
        $this->assertDatabaseHas('users', ['id' => $second->id, 'keycloak_id' => null]);
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

        // Role existing tidak dioverwrite, sehingga tidak ada audit inisialisasi role untuk user ini.
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'User',
            'auditable_id' => $user->id,
        ]);
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

    public function test_missing_email_is_denied_even_for_existing_local_user(): void
    {
        // Jalur whitelist dev-username dihapus: seluruh login wajib via identitas
        // Keycloak asli; akun lokal tanpa email terverifikasi tidak diotorisasi.
        User::factory()->superAdmin()->create([
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

        $response->assertOk();
        $response->assertSee('Akun Keycloak belum terdaftar');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'keycloak_id' => 'kc-demo',
        ]);
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
        // Bootstrap user SIMPEG pertama dulu agar akun SSO baru mendapat default pegawai.
        User::factory()->superAdmin()->create();

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Demo Role',
            'email' => 'demo-role@example.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-role',
            'nickname' => 'demo-role',
            'name' => 'Demo Role',
            'email' => 'demo-role@example.com',
            'raw' => [
                'email' => 'demo-role@example.com',
                'email_verified' => true,
                'preferred_username' => 'demo-role',
                'realm_access' => ['roles' => ['super_admin']],
            ],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'email' => 'demo-role@example.com',
            'keycloak_id' => 'kc-role',
            'employee_id' => $employee->id,
            // Claim role Keycloak (super_admin) tidak pernah menjadi sumber RBAC SIMPEG.
            'role' => 'pegawai',
        ]);
    }

    /** Claim role Keycloak tidak pernah menjadi sumber RBAC; user baru non-bootstrap tetap mendapat default pegawai. */
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

    public function test_database_seeder_creates_uat_sso_accounts(): void
    {
        // DatabaseSeeder menanam akun UAT SSO (email + preferred_username + role)
        // yang dipakai persona browser QA dan evidence UAT Issue #6.
        $this->seed(DatabaseSeeder::class);

        foreach (SsoRoleMappedAccountSeeder::UAT_ACCOUNTS as $uatAccount) {
            $user = User::where('email', $uatAccount['email'])->first();

            $this->assertNotNull($user, "Akun UAT {$uatAccount['email']} harus ada.");
            $this->assertSame($uatAccount['username'], $user->keycloak_username, "Preferred username akun UAT {$uatAccount['email']} harus sesuai fixture.");
            $this->assertSame($uatAccount['role'], $user->role, "Role akun UAT {$uatAccount['email']} harus sesuai fixture.");
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
     * Email Keycloak terverifikasi hanya dipakai untuk mapping akun; callback tidak menimpa
     * email kontak pegawai (email_pribadi) yang merupakan Data Utama kelolaan Admin Kepegawaian.
     */
    public function test_callback_does_not_overwrite_employee_email_pribadi(): void
    {
        $employee = Employee::factory()->create([
            'email_pribadi' => 'kontak@example.com',
        ]);
        User::factory()->create([
            'email' => 'kontak@example.com',
            'keycloak_id' => 'kc-no-overwrite',
            'employee_id' => $employee->id,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-no-overwrite',
            'nickname' => 'no-overwrite',
            'name' => 'No Overwrite',
            'email' => 'sso@example.com',
            'raw' => ['email' => 'sso@example.com', 'email_verified' => true, 'preferred_username' => 'no-overwrite'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email_pribadi' => 'kontak@example.com',
        ]);
    }

    /** Pegawai berstatus Non-Aktif tidak boleh mendapat role baru via SSO. */
    public function test_role_not_initialized_for_non_active_employee(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pensiunan Belum Dihapus',
            'email' => 'pensiun@example.com',
            'status_aktif' => 'Pensiun',
        ]);

        // User terpeta milik pegawai nonaktif, role masih kosong.
        $user = User::factory()->create([
            'email' => 'pensiun@example.com',
            'keycloak_id' => 'kc-pensiun-tanpa-softdel',
            'employee_id' => $employee->id,
            'role' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-pensiun-tanpa-softdel',
            'nickname' => 'pensiun-user',
            'name' => 'Pensiunan Belum Dihapus',
            'email' => 'pensiun@example.com',
            'raw' => ['email' => 'pensiun@example.com', 'email_verified' => true, 'preferred_username' => 'pensiun-user'],
        ]);

        $this->get('/auth/keycloak/callback');

        // Role tetap kosong: pegawai Pensiun tanpa soft-delete tidak mendapat role baru.
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'employee_id' => $employee->id,
            'role' => null,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'User',
            'auditable_id' => $user->id,
        ]);
    }

    /** Username case collision: User B punya 'foo', User A punya 'Foo'; Keycloak kirim 'foo' untuk A → username A tidak berubah. */
    public function test_username_case_collision_does_not_violate_unique_constraint(): void
    {
        $employeeA = Employee::factory()->create(['email' => 'user-a@example.com']);
        $userA = User::factory()->create([
            'email' => 'user-a@example.com',
            'keycloak_id' => 'kc-user-a',
            'keycloak_username' => 'Foo',
            'employee_id' => $employeeA->id,
            'role' => 'pegawai',
        ]);

        // User B sudah memegang 'foo' (huruf kecil).
        User::factory()->create([
            'email' => 'user-b@example.test',
            'keycloak_username' => 'foo',
            'role' => 'pegawai',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-user-a',
            'nickname' => 'foo', // Keycloak mengirim lowercase
            'name' => 'User A',
            'email' => 'user-a@example.com',
            'raw' => ['email' => 'user-a@example.com', 'email_verified' => true, 'preferred_username' => 'foo'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        // Username A tetap 'Foo' karena 'foo' sudah dimiliki User B.
        $this->assertDatabaseHas('users', [
            'id' => $userA->id,
            'keycloak_username' => 'Foo',
        ]);
    }

    /** Role fixture mapping (kini di seeder) tidak pernah meng-elevate role; akun baru tetap pegawai. */
    public function test_mixed_case_role_mapping_key_does_not_elevate_new_login(): void
    {
        User::factory()->superAdmin()->create();

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Kabag SSO',
            'email' => 'kabag@example.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-kabag-case',
            'nickname' => 'kabag-case',
            'name' => 'Kabag SSO',
            'email' => 'kabag@example.com',
            'raw' => ['email' => 'kabag@example.com', 'email_verified' => true, 'preferred_username' => 'kabag-case'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'email' => 'kabag@example.com',
            'keycloak_id' => 'kc-kabag-case',
            'employee_id' => $employee->id,
            'role' => 'pegawai',
        ]);
    }

    /** User existing ber-role kosong mengabaikan role_mapping (termasuk key mixed-case) dan diinisialisasi pegawai. */
    public function test_existing_blank_role_ignores_mixed_case_mapping_key(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Admin SSO',
            'email' => 'admin@example.com',
        ]);

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'employee_id' => $employee->id,
            'role' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-admin-case',
            'nickname' => 'admin-case',
            'name' => 'Admin SSO',
            'email' => 'admin@example.com',
            'raw' => ['email' => 'admin@example.com', 'email_verified' => true, 'preferred_username' => 'admin-case'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'pegawai',
        ]);
    }

    /**
     * Regression defect utama Issue #6: user existing milik pegawai yang sama wajib dipakai
     * ulang via employee_id meskipun email internalnya berbeda dari email SSO terverifikasi.
     * Jumlah user tidak bertambah, role existing dipertahankan, dan binding pertama diaudit.
     */
    public function test_existing_user_is_reused_via_employee_id_before_email_fallback(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Dayen Kanonis',
            'email_pribadi' => 'dayen.sso@example.test',
        ]);

        $existingUser = User::factory()->create([
            'email' => 'dayen-internal@lldikti.go.id',
            'employee_id' => $employee->id,
            'role' => 'pimpinan',
        ]);

        $usersBefore = User::query()->count();

        $this->fakeKeycloakUser([
            'id' => 'kc-new-subject',
            'nickname' => 'dayen-sso',
            'name' => 'Dayen SSO',
            'email' => 'dayen.sso@example.test',
            'raw' => ['email' => 'dayen.sso@example.test', 'email_verified' => true, 'preferred_username' => 'dayen-sso'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($existingUser);

        // Jumlah user tidak bertambah: user existing dipakai ulang, bukan dibuat baru.
        $this->assertSame($usersBefore, User::query()->count());

        $existingUser->refresh();
        // Subject Keycloak baru terikat pada user yang sama.
        $this->assertSame('kc-new-subject', $existingUser->keycloak_id);
        // Role internal existing dipertahankan.
        $this->assertSame('pimpinan', $existingUser->role);
        // Email internal tidak ditimpa oleh email SSO.
        $this->assertSame('dayen-internal@lldikti.go.id', $existingUser->email);
        $this->assertSame($employee->id, $existingUser->employee_id);

        // Binding keycloak_id pertama tercatat sebagai audit — subject tersamarkan,
        // nilai mentah TIDAK boleh masuk payload append-only.
        $binding = AuditLog::query()->where('event', 'SSO_BINDING')->where('auditable_id', $existingUser->id)->sole();
        $this->assertSame('**********ject', $binding->new_values['keycloak_id_masked'] ?? null);
        $this->assertArrayNotHasKey('keycloak_id', $binding->new_values);
        $this->assertStringNotContainsString('kc-new-subject', json_encode($binding->toArray()));
    }

    /**
     * Konflik identitas: resolver employee_id dan email fallback menunjuk dua user berbeda
     * → login ditolak fail-closed, tidak ada binding, dan penolakan tercatat audit.
     */
    public function test_identity_conflict_between_employee_user_and_email_user_is_rejected(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Korban Konflik',
            'email_pribadi' => 'dayen.sso@example.test',
        ]);

        $userByEmployee = User::factory()->create([
            'email' => 'internal-a@lldikti.go.id',
            'employee_id' => $employee->id,
            'role' => 'pimpinan',
        ]);
        $userByEmail = User::factory()->create([
            'email' => 'dayen.sso@example.test',
            'role' => 'pegawai',
        ]);

        $usersBefore = User::query()->count();

        $this->fakeKeycloakUser([
            'id' => 'kc-conflict-subject',
            'nickname' => 'conflict-sso',
            'name' => 'Konflik SSO',
            'email' => 'dayen.sso@example.test',
            'raw' => ['email' => 'dayen.sso@example.test', 'email_verified' => true, 'preferred_username' => 'conflict-sso'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Konflik identitas akun SIMPEG terdeteksi.');
        $this->assertGuest();

        // Tidak ada user baru maupun binding yang terjadi.
        $this->assertSame($usersBefore, User::query()->count());
        $this->assertNull($userByEmployee->refresh()->keycloak_id);
        $this->assertNull($userByEmail->refresh()->keycloak_id);

        // Penolakan konflik identitas tercatat audit tanpa payload mentah Keycloak.
        $audit = AuditLog::query()->where('event', 'SSO_MAPPING_REJECTED')->latest('created_at')->first();
        $this->assertNotNull($audit);
        $this->assertSame('identity_conflict', $audit->new_values['reason'] ?? null);
        $this->assertSame($userByEmployee->id, $audit->auditable_id ?? null);
    }

    /**
     * Pegawai non-aktif (kelompok status bukan Aktif) tidak pernah menjadi pintu masuk
     * akun SSO baru: permukaan autentikasi hanya memetakan pegawai aktif, sehingga
     * percobaan login ditolak sebagai not-found dan tercatat audit keamanan.
     */
    public function test_mapping_rejection_for_inactive_employee_is_audited(): void
    {
        Employee::factory()->create([
            'nama_lengkap' => 'Pensiunan Baru',
            'email' => 'pensiun-baru@example.com',
            'status_aktif' => 'Pensiun',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-pensiun-baru',
            'nickname' => 'pensiun-baru',
            'name' => 'Pensiunan Baru',
            'email' => 'pensiun-baru@example.com',
            'raw' => ['email' => 'pensiun-baru@example.com', 'email_verified' => true, 'preferred_username' => 'pensiun-baru'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Akun Keycloak belum terdaftar sebagai pegawai SIMPEG.');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'email' => 'pensiun-baru@example.com',
        ]);

        $audit = AuditLog::query()->where('event', 'SSO_MAPPING_REJECTED')->latest('created_at')->first();
        $this->assertNotNull($audit);
        $this->assertSame('employee_match_not_found', $audit->new_values['reason'] ?? null);
        $this->assertNull($audit->user_id);
    }

    /**
     * Simulasi dua callback paralel untuk employee + subject yang sama: callback "pemenang"
     * sudah membuat dan mengikat user tepat saat callback kita memegang lock employee.
     * Re-check setelah lock wajib memakai ulang user itu — jumlah user tetap satu, tanpa
     * unique violation yang bocor ke pengguna.
     */
    public function test_parallel_callback_same_employee_and_subject_creates_single_user(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Paralel Satu',
            'email' => 'paralel-satu@example.com',
        ]);

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected, $employee): void {
            if ($injected
                || ! str_contains((string) $query->sql, 'from "employees"')
                || ! str_contains((string) $query->sql, 'for update')) {
                return;
            }

            $injected = true;

            // Callback "pemenang" paralel sudah membuat & mengikat user untuk pegawai yang sama.
            User::factory()->create([
                'email' => 'paralel-satu@example.com',
                'keycloak_id' => 'kc-paralel-1',
                'employee_id' => $employee->id,
                'role' => 'pegawai',
            ]);
        });

        $this->fakeKeycloakUser([
            'id' => 'kc-paralel-1',
            'nickname' => 'paralel-satu',
            'name' => 'Paralel Satu',
            'email' => 'paralel-satu@example.com',
            'raw' => ['email' => 'paralel-satu@example.com', 'email_verified' => true, 'preferred_username' => 'paralel-satu'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        // Tepat satu user untuk pegawai tersebut, dengan binding subject yang benar.
        $this->assertSame(1, User::where('employee_id', $employee->id)->count());
        $this->assertSame('kc-paralel-1', User::where('employee_id', $employee->id)->first()->keycloak_id);

        // User pemenang sudah terikat sejak awal → tidak ada first binding baru.
        $this->assertSame(0, AuditLog::query()->where('event', 'SSO_BINDING')->count());
    }

    /**
     * Simulasi dua callback paralel dengan preferred_username sama (employee berbeda):
     * user lain merebut username tepat setelah pemeriksaan ketersediaan. Unique constraint
     * menjadi authority terakhir — save diulang TANPA username tersebut, login tetap sukses
     * (identitas kanonis keycloak_id), tidak ada HTTP 500 dan tidak ada exception bocor.
     */
    public function test_parallel_callback_with_same_preferred_username_does_not_fail_login(): void
    {
        User::factory()->superAdmin()->create();

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Paralel Username',
            'email' => 'paralel-username@example.com',
        ]);

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if ($injected || ! str_contains((string) $query->sql, 'keycloak_username')) {
                return;
            }

            $injected = true;

            // Callback paralel (employee berbeda) merebut preferred_username yang sama.
            User::factory()->create([
                'email' => 'rival-username@example.com',
                'keycloak_username' => 'paralel-user',
                'role' => 'pegawai',
            ]);
        });

        $this->fakeKeycloakUser([
            'id' => 'kc-paralel-user',
            'nickname' => 'paralel-user',
            'name' => 'Paralel Username',
            'email' => 'paralel-username@example.com',
            'raw' => ['email' => 'paralel-username@example.com', 'email_verified' => true, 'preferred_username' => 'paralel-user'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $mappedUser = User::where('employee_id', $employee->id)->first();
        $this->assertSame('kc-paralel-user', $mappedUser->keycloak_id);
        // Username tidak direbut dari pemilik sahnya; login tetap identitas keycloak_id.
        $this->assertNotSame('paralel-user', $mappedUser->keycloak_username);
        $this->assertDatabaseHas('users', [
            'email' => 'rival-username@example.com',
            'keycloak_username' => 'paralel-user',
        ]);

        // First binding tetap ter-audit meskipun save sempat diulang.
        $this->assertSame(1, AuditLog::query()->where('event', 'SSO_BINDING')->where('auditable_id', $mappedUser->id)->count());
    }

    /**
     * Simulasi race pembuatan user: user untuk pegawai + subject yang sama muncul tepat
     * setelah resolver melihat "belum ada user". Save gagal unique constraint → execute()
     * re-resolve dengan state terbaru → user pemenang dipakai ulang → tepat satu user,
     * tanpa duplicate dan tanpa exception yang bocor ke pengguna.
     */
    public function test_unique_violation_during_save_re_resolves_existing_bound_user(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Paralel Reuse',
            'email' => 'paralel-reuse@example.com',
        ]);

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected, $employee): void {
            if ($injected || ! str_contains((string) $query->sql, 'keycloak_username')) {
                return;
            }

            $injected = true;

            // Callback "pemenang" paralel baru saja commit user untuk pegawai + subject sama.
            User::factory()->create([
                'email' => 'paralel-reuse@example.com',
                'keycloak_id' => 'kc-paralel-2',
                'employee_id' => $employee->id,
                'role' => 'pegawai',
            ]);
        });

        $this->fakeKeycloakUser([
            'id' => 'kc-paralel-2',
            'nickname' => 'paralel-reuse',
            'name' => 'Paralel Reuse',
            'email' => 'paralel-reuse@example.com',
            'raw' => ['email' => 'paralel-reuse@example.com', 'email_verified' => true, 'preferred_username' => 'paralel-reuse'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $this->assertSame(1, User::where('employee_id', $employee->id)->count());
        $reused = User::where('employee_id', $employee->id)->first();
        $this->assertSame('kc-paralel-2', $reused->keycloak_id);
        $this->assertSame('pegawai', $reused->role);
    }

    /** Nol employee cocok → respons terkontrol + audit employee_match_not_found tanpa payload mentah Keycloak. */
    public function test_zero_employee_match_is_rejected_and_audited(): void
    {
        $this->fakeKeycloakUser([
            'id' => 'kc-tak-terdaftar',
            'nickname' => 'tak-terdaftar',
            'name' => 'Tak Terdaftar',
            'email' => 'tidak-terdaftar@example.com',
            'raw' => ['email' => 'tidak-terdaftar@example.com', 'email_verified' => true, 'preferred_username' => 'tak-terdaftar'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Akun Keycloak belum terdaftar sebagai pegawai SIMPEG.');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'email' => 'tidak-terdaftar@example.com',
        ]);

        $audit = AuditLog::query()->where('event', 'SSO_MAPPING_REJECTED')->latest('created_at')->first();
        $this->assertNotNull($audit);
        $this->assertSame('employee_match_not_found', $audit->new_values['reason'] ?? null);
        $this->assertNull($audit->user_id);
        $this->assertSame('SSO Callback', $audit->user_name);

        // Audit hanya memuat metadata aman — tidak ada payload/token/claim mentah Keycloak.
        $this->assertSame(['reason', 'email', 'employee_id', 'source'], array_keys($audit->new_values));
        $this->assertStringNotContainsString('token', json_encode($audit->toArray()));
    }

    /** Lebih dari satu employee cocok → ambigu, fail-closed + audit employee_match_ambiguous. */
    public function test_ambiguous_employee_match_is_rejected_and_audited(): void
    {
        // Employee A cocok via email_pribadi kanonis; Employee B via kolom email legacy
        // (data lama/impor yang tidak lewat mutator — email_pribadi-nya berbeda).
        Employee::factory()->create([
            'nama_lengkap' => 'Ambyu A',
            'email' => 'ambigu@example.com',
        ]);
        $employeeB = Employee::factory()->create([
            'nama_lengkap' => 'Ambyu B',
        ]);
        DB::table('employees')->where('id', $employeeB->id)->update(['email' => 'ambigu@example.com']);

        $this->fakeKeycloakUser([
            'id' => 'kc-ambigu',
            'nickname' => 'ambigu',
            'name' => 'Ambyu SSO',
            'email' => 'ambigu@example.com',
            'raw' => ['email' => 'ambigu@example.com', 'email_verified' => true, 'preferred_username' => 'ambigu'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertOk();
        $response->assertSee('Akun Keycloak belum terdaftar sebagai pegawai SIMPEG.');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'email' => 'ambigu@example.com',
        ]);

        $audit = AuditLog::query()->where('event', 'SSO_MAPPING_REJECTED')->latest('created_at')->first();
        $this->assertNotNull($audit);
        $this->assertSame('employee_match_ambiguous', $audit->new_values['reason'] ?? null);
        $this->assertStringNotContainsString('token', json_encode($audit->toArray()));
    }

    /**
     * Audit SSO_BINDING tidak pernah menyimpan subject Keycloak mentah — payload
     * append-only hanya memuat representasi tersamarkan (konsisten dengan jalur
     * pemetaan admin di UpdateUserMappingAction).
     */
    public function test_binding_audit_does_not_store_raw_keycloak_subject(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Subjek Terbind',
            'email' => 'subjek-bind@example.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-subjek-rahasia-9f2a',
            'nickname' => 'subjek-bind',
            'name' => 'Subjek Terbind',
            'email' => 'subjek-bind@example.com',
            'raw' => ['email' => 'subjek-bind@example.com', 'email_verified' => true, 'preferred_username' => 'subjek-bind'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        $binding = AuditLog::query()->where('event', 'SSO_BINDING')->sole();
        $this->assertSame('9f2a', substr((string) ($binding->new_values['keycloak_id_masked'] ?? ''), -4));
        $this->assertStringContainsString('*', (string) $binding->new_values['keycloak_id_masked']);
        $this->assertStringNotContainsString('kc-subjek-rahasia-9f2a', json_encode($binding->toArray()));
    }

    /**
     * User yang dipakai ulang via employee_id dengan email internal berbeda TIDAK
     * mendapat email_verified_at dari klaim SSO — Keycloak hanya memverifikasi email
     * SSO-nya, bukan email internal SIMPEG; nilai existing juga tidak pernah dicabut.
     */
    public function test_reused_user_with_different_internal_email_keeps_email_verification_untouched(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Verifikasi Terjaga',
            'email_pribadi' => 'sso-terverifikasi@example.com',
        ]);

        $existingUser = User::factory()->create([
            'email' => 'internal-terjaga@lldikti.go.id',
            'employee_id' => $employee->id,
            'role' => 'pimpinan',
            'email_verified_at' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-verifikasi',
            'nickname' => 'verifikasi',
            'name' => 'Verifikasi Terjaga',
            'email' => 'sso-terverifikasi@example.com',
            'raw' => ['email' => 'sso-terverifikasi@example.com', 'email_verified' => true, 'preferred_username' => 'verifikasi'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        // Email internal yang tidak pernah diverifikasi Keycloak tetap tidak terverifikasi.
        $this->assertNull($existingUser->refresh()->email_verified_at);
    }

    /** User yang emailnya sama dengan klaim SSO terverifikasi tetap sah ditandai terverifikasi. */
    public function test_user_with_matching_verified_email_gets_email_verified_at(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Email Kanonis',
            'email' => 'kanonis@example.com',
        ]);

        $existingUser = User::factory()->create([
            'email' => 'kanonis@example.com',
            'employee_id' => $employee->id,
            'role' => 'pimpinan',
            'email_verified_at' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-kanonis',
            'nickname' => 'kanonis',
            'name' => 'Email Kanonis',
            'email' => 'kanonis@example.com',
            'raw' => ['email' => 'kanonis@example.com', 'email_verified' => true, 'preferred_username' => 'kanonis'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        $this->assertNotNull($existingUser->refresh()->email_verified_at);
    }

    /**
     * Audit penolakan yang menyentuh akun berisi aktor SISTEM, bukan pemilik akun —
     * pemilik akun belum terautentikasi dan mungkin korban percobaan pengikatan.
     */
    public function test_identity_conflict_audit_uses_system_actor_not_victim_account(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Korban Aktor',
            'email_pribadi' => 'aktor-korban@example.com',
        ]);

        $userByEmployee = User::factory()->create([
            'email' => 'aktor-internal@lldikti.go.id',
            'employee_id' => $employee->id,
            'role' => 'pimpinan',
            'name' => 'Korban Aktor',
        ]);
        User::factory()->create([
            'email' => 'aktor-korban@example.com',
            'role' => 'pegawai',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-aktor-korban',
            'nickname' => 'aktor-korban',
            'name' => 'Korban Aktor',
            'email' => 'aktor-korban@example.com',
            'raw' => ['email' => 'aktor-korban@example.com', 'email_verified' => true, 'preferred_username' => 'aktor-korban'],
        ]);

        $this->get('/auth/keycloak/callback')->assertOk();

        $audit = AuditLog::query()->where('event', 'SSO_MAPPING_REJECTED')->latest('created_at')->first();
        $this->assertNotNull($audit);
        $this->assertSame('identity_conflict', $audit->new_values['reason'] ?? null);
        // Aktor sistem: user korban hanya sebagai objek audit, bukan pelaku.
        $this->assertNull($audit->user_id);
        $this->assertSame('SSO Callback', $audit->user_name);
        $this->assertSame($userByEmployee->id, $audit->auditable_id ?? null);
    }

    /**
     * Race pengikatan subject antar proses: initial lookup keycloak_id belum menemukan
     * user, lalu request/admin lain mengikat subject yang sama ke User B sebelum
     * re-check transaksional. Resolver wajib merekonsiliasi userBySubject dan MENOLAK
     * mapping secara terkontrol (bukan unique violation / HTTP 500), tanpa rebind,
     * dengan audit rejection aktor sistem.
     */
    public function test_subject_bound_to_another_user_during_resolver_is_rejected_without_rebind(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Kandidat Subject',
            'email' => 'kandidat-subject@example.com',
        ]);
        $otherEmployee = Employee::factory()->create([
            'nama_lengkap' => 'Pemilik Subject',
        ]);

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected, $otherEmployee): void {
            if ($injected
                || ! str_contains((string) $query->sql, 'from "employees"')
                || ! str_contains((string) $query->sql, 'for update')) {
                return;
            }

            $injected = true;

            // Pihak lain (admin mapping / callback paralel) mengikat subject yang sama
            // ke User B — pegawai berbeda — tepat setelah callback kita memegang lock.
            User::factory()->create([
                'email' => 'pemilik-subject@example.com',
                'keycloak_id' => 'kc-race-subject',
                'employee_id' => $otherEmployee->id,
                'role' => 'pegawai',
            ]);
        });

        $this->fakeKeycloakUser([
            'id' => 'kc-race-subject',
            'nickname' => 'kandidat-subject',
            'name' => 'Kandidat Subject',
            'email' => 'kandidat-subject@example.com',
            'raw' => ['email' => 'kandidat-subject@example.com', 'email_verified' => true, 'preferred_username' => 'kandidat-subject'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        // Controlled rejection, bukan HTTP 500 / unique violation yang bocor.
        $response->assertOk();
        $response->assertSee('Akun SIMPEG sudah terhubung ke SSO lain.');
        $this->assertGuest();

        // Tidak ada rebind: tidak ada user baru untuk pegawai kandidat, dan pegawai
        // pemilik subject tidak kehilangan bindingnya (state transaksional rollback
        // bersih — binding milik pemilik subject tetap satu).
        $this->assertSame(0, User::where('employee_id', $employee->id)->count());

        // Audit rejection aktor sistem tersedia tanpa payload mentah Keycloak.
        $audit = AuditLog::query()->where('event', 'SSO_MAPPING_REJECTED')->latest('created_at')->first();
        $this->assertNotNull($audit);
        $this->assertSame('sso_subject_conflict', $audit->new_values['reason'] ?? null);
        $this->assertNull($audit->user_id);
        $this->assertSame('SSO Callback', $audit->user_name);
        $this->assertStringNotContainsString('kc-race-subject', json_encode($audit->toArray()));
    }

    /**
     * P1 race pada boundary save: resolver melepas lock employee sebelum loginMappedUser
     * menyimpan. Callback kedua dengan subject berbeda untuk pegawai yang sama dapat
     * membaca binding kosong lalu MENIMPA subject pertama lewat UPDATE baris yang sama
     * (tanpa unique violation). Re-check terkunci tepat sebelum save wajib menolak
     * rebind secara terkontrol.
     */
    public function test_subject_rebound_by_concurrent_save_is_rejected_without_overwrite(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Kandidat Rebind',
            'email' => 'kandidat-rebind@example.com',
        ]);

        $user = User::factory()->create([
            'email' => 'kandidat-rebind@example.com',
            'employee_id' => $employee->id,
            'role' => 'pegawai',
            'keycloak_id' => null,
        ]);

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected, $user): void {
            // Suntik TEPAT SETELAH resolver membaca userByEmployee (setelah userBySubject
            // lewat, sebelum fresh-lock save): simulasi callback/admin lain mengikat
            // subject-nya di sela waktu antara resolusi dan penyimpanan.
            if ($injected
                || ! str_contains((string) $query->sql, 'from "users" where "employee_id" =')
                || ! str_contains((string) $query->sql, 'for update')) {
                return;
            }

            $injected = true;

            // Callback paralel (subject berbeda) keburu menyimpan bindingnya lebih dulu.
            DB::table('users')->where('id', $user->id)->update(['keycloak_id' => 'kc-pemenang-lain']);
        });

        $this->fakeKeycloakUser([
            'id' => 'kc-kandidat-rebind',
            'nickname' => 'kandidat-rebind',
            'name' => 'Kandidat Rebind',
            'email' => 'kandidat-rebind@example.com',
            'raw' => ['email' => 'kandidat-rebind@example.com', 'email_verified' => true, 'preferred_username' => 'kandidat-rebind'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        // Controlled rejection, bukan penimpaan binding / HTTP 500.
        $response->assertOk();
        $response->assertSee('Akun SIMPEG sudah terhubung ke SSO lain.');
        $this->assertGuest();

        // Subject pemenang TIDAK tertimpa oleh kandidat.
        $this->assertSame('kc-pemenang-lain', $user->refresh()->keycloak_id);

        // Audit rejection aktor sistem tersedia tanpa subject mentah.
        $audit = AuditLog::query()->where('event', 'SSO_MAPPING_REJECTED')->latest('created_at')->first();
        $this->assertNotNull($audit);
        $this->assertSame('sso_subject_conflict', $audit->new_values['reason'] ?? null);
        $this->assertNull($audit->user_id);
        $this->assertSame($user->id, $audit->auditable_id ?? null);
        $this->assertStringNotContainsString('kc-kandidat-rebind', json_encode($audit->toArray()));
    }

    /**
     * Akun yang SUDAH terikat keycloak_id tetap boleh mendapat penandaan verifikasi
     * email bila email kanonisnya sama dengan klaim email Keycloak terverifikasi
     * (mis. akun dipetakan manual oleh admin, lalu login SSO pertama).
     */
    public function test_bound_account_with_matching_verified_email_gets_email_verified_at(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Terikat Kanonis',
            'email' => 'terikat-kanonis@example.com',
        ]);

        $user = User::factory()->create([
            'email' => 'terikat-kanonis@example.com',
            'keycloak_id' => 'kc-terikat-kanonis',
            'employee_id' => $employee->id,
            'role' => 'pimpinan',
            'email_verified_at' => null,
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-terikat-kanonis',
            'nickname' => 'terikat-kanonis',
            'name' => 'Terikat Kanonis',
            'email' => 'terikat-kanonis@example.com',
            'raw' => ['email' => 'terikat-kanonis@example.com', 'email_verified' => true, 'preferred_username' => 'terikat-kanonis'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * Dua callback paralel dengan subject yang sama untuk user yang keycloak_id-nya
     * masih kosong: callback pemenang menyimpan lebih dulu, callback kedua memperoleh
     * lock dan melihat subject yang sama (bukan konflik). Status binding/role wajib
     * dihitung ulang dari state terkini DI DALAM lock agar satu pengikatan pertama
     * tidak menghasilkan audit SSO_BINDING / inisialisasi role duplikat.
     */
    public function test_parallel_same_subject_callbacks_write_single_binding_audit(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Subject Kembar',
            'email' => 'subject-kembar@example.com',
        ]);

        $user = User::factory()->create([
            'email' => 'subject-kembar@example.com',
            'employee_id' => $employee->id,
            'role' => null,
            'keycloak_id' => null,
        ]);

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected, $user): void {
            // Suntik TEPAT SETELAH resolver membaca userByEmployee: simulasi callback
            // "pemenang" paralel yang sudah menyimpan subject + inisialisasi role yang sama.
            if ($injected
                || ! str_contains((string) $query->sql, 'from "users" where "employee_id" =')
                || ! str_contains((string) $query->sql, 'for update')) {
                return;
            }

            $injected = true;

            DB::table('users')->where('id', $user->id)->update([
                'keycloak_id' => 'kc-kembar',
                'role' => 'pegawai',
            ]);
        });

        $this->fakeKeycloakUser([
            'id' => 'kc-kembar',
            'nickname' => 'subject-kembar',
            'name' => 'Subject Kembar',
            'email' => 'subject-kembar@example.com',
            'raw' => ['email' => 'subject-kembar@example.com', 'email_verified' => true, 'preferred_username' => 'subject-kembar'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        // Login tetap sukses (subject sama = bukan konflik).
        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        // Tidak ada audit duplikat: pengikatan pertama + inisialisasi role sudah
        // dilakukan callback pemenang, callback kedua tidak menulis ulang evidencenya.
        $this->assertSame(0, AuditLog::query()->where('event', 'SSO_BINDING')->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'UPDATE')->where('auditable_type', 'User')->count());
    }

    /**
     * P1 bootstrap race: keputusan akun pertama sistem wajib atomik lintas pegawai.
     * Resolver mengambil advisory lock transaksional sebelum membaca exists() —
     * regresi terjadi bila statement lock tidak lagi dieksekusi pada cabang user baru
     * (dua callback paralel pegawai berbeda bisa sama-sama bootstrap super_admin).
     */
    public function test_bootstrap_takes_transactional_advisory_lock_on_first_account(): void
    {
        $statements = [];
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$statements): void {
            if (str_contains((string) $query->sql, 'pg_advisory_xact_lock')) {
                $statements[] = $query->sql;
            }
        });

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Bootstrap Atomic',
            'email' => 'bootstrap-atomic@example.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-bootstrap-atomic',
            'nickname' => 'bootstrap-atomic',
            'name' => 'Bootstrap Atomic',
            'email' => 'bootstrap-atomic@example.com',
            'raw' => ['email' => 'bootstrap-atomic@example.com', 'email_verified' => true, 'preferred_username' => 'bootstrap-atomic'],
        ]);

        $this->get('/auth/keycloak/callback')->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('users', [
            'email' => 'bootstrap-atomic@example.com',
            'role' => 'super_admin',
        ]);
        $this->assertNotEmpty($statements, 'Cabang bootstrap user baru wajib mengambil pg_advisory_xact_lock.');
    }

    /**
     * P2 race username pada akun yang SUDAH terikat: akun mengubah keycloak_username
     * ke klaim baru, tetapi user lain merebutnya sebelum save. Unique violation wajib
     * di-retry dengan melepas perubahan username (bukan hanya klaim baru) — login tetap
     * sukses, username lama dipertahankan, tanpa HTTP 500.
     */
    public function test_existing_account_losing_username_race_keeps_previous_username(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pemilik Username Lama',
            'email' => 'username-lama@example.com',
        ]);

        $user = User::factory()->create([
            'email' => 'username-lama@example.com',
            'keycloak_id' => 'kc-username-lama',
            'keycloak_username' => 'username-lama',
            'employee_id' => $employee->id,
            'role' => 'pegawai',
        ]);

        $injected = false;
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$injected): void {
            if ($injected || ! str_contains((string) $query->sql, 'keycloak_username')) {
                return;
            }

            $injected = true;

            // Callback paralel merebut username baru yang hendak diklaim akun terikat ini.
            User::factory()->create([
                'email' => 'perebut-username@example.com',
                'keycloak_username' => 'username-lama-baru',
                'role' => 'pegawai',
            ]);
        });

        $this->fakeKeycloakUser([
            'id' => 'kc-username-lama',
            'nickname' => 'username-lama-baru',
            'name' => 'Pemilik Username Lama',
            'email' => 'username-lama@example.com',
            'raw' => ['email' => 'username-lama@example.com', 'email_verified' => true, 'preferred_username' => 'username-lama-baru'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        // Login tetap sukses (identitas kanonis keycloak_id sama) — bukan HTTP 500.
        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        // Perubahan username dilepas: pemilik sah mempertahankan username lamanya.
        $this->assertSame('username-lama', $user->refresh()->keycloak_username);
        $this->assertDatabaseHas('users', [
            'email' => 'perebut-username@example.com',
            'keycloak_username' => 'username-lama-baru',
        ]);
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
