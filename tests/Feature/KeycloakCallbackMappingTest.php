<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
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
        $this->assertSame('sso_mapping', $audit->new_values['source'] ?? null);
    }

    public function test_first_login_with_mapped_email_gets_mapped_role_instead_of_bootstrap_super_admin(): void
    {
        config()->set('services.keycloak.role_mapping', [
            'kabag@example.com' => 'kepala_bagian',
        ]);

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
            'role' => 'kepala_bagian',
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

        config()->set('services.keycloak.role_mapping', [
            'dayensite@gmail.com' => 'super_admin',
        ]);

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Superadmin LLDIKTI16',
            'email' => 'dayensite@gmail.com',
        ]);

        $this->fakeKeycloakUser([
            'id' => 'kc-dayensite',
            'nickname' => 'demo-klabat', // benturan: username sama dengan milik user demo
            'name' => 'Superadmin LLDIKTI16',
            'email' => 'dayensite@gmail.com',
            'raw' => ['email' => 'dayensite@gmail.com', 'email_verified' => true, 'preferred_username' => 'demo-klabat'],
        ]);

        $response = $this->get('/auth/keycloak/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        // Login sukses: keycloak_id terisi, role dari mapping tetap diberikan,
        // dan keycloak_username yang bentrok TIDAK menimpa milik user demo.
        $mappedUser = User::where('email', 'dayensite@gmail.com')->first();
        $this->assertSame('kc-dayensite', $mappedUser->keycloak_id);
        $this->assertSame('super_admin', $mappedUser->role);
        $this->assertNotSame('demo-klabat', $mappedUser->keycloak_username);

        // Pemilik asli username tidak berubah.
        $this->assertDatabaseHas('users', [
            'email' => 'demo-klabat@example.test',
            'keycloak_username' => 'demo-klabat',
        ]);
    }

    /** User baru ter-map valid dengan email di role_mapping mendapat role pemetaan, bukan default pegawai. */
    public function test_new_mapped_login_after_bootstrap_gets_mapped_role_instead_of_pegawai(): void
    {
        User::factory()->superAdmin()->create();

        config()->set('services.keycloak.role_mapping', [
            'kepeg@example.com' => 'admin_kepegawaian',
        ]);

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
            'role' => 'admin_kepegawaian',
        ]);

        $audit = AuditLog::query()->where('event', 'UPDATE')->where('auditable_type', 'User')->sole();
        $this->assertSame(['role' => null], $audit->old_values);
        $this->assertSame('admin_kepegawaian', $audit->new_values['role'] ?? null);
        $this->assertSame('sso_mapping', $audit->new_values['source'] ?? null);
    }

    /** Role internal yang sudah ditetapkan tidak pernah dioverwrite oleh role_mapping email SSO. */
    public function test_existing_role_is_never_overwritten_by_email_mapping(): void
    {
        config()->set('services.keycloak.role_mapping', [
            'pimpinan@example.com' => 'pimpinan',
        ]);

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

    /** User lama ber-role kosong dengan email ter-map diinisialisasi ke role pemetaan saat login; inisialisasi diaudit. */
    public function test_existing_mapped_user_with_blank_role_gets_mapped_role_on_login(): void
    {
        config()->set('services.keycloak.role_mapping', [
            'dayen@example.com' => 'kepala_bagian',
        ]);

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
            'role' => 'kepala_bagian',
        ]);

        $audit = AuditLog::query()->where('event', 'UPDATE')->where('auditable_type', 'User')->sole();
        $this->assertSame(['role' => null], $audit->old_values);
        $this->assertSame('kepala_bagian', $audit->new_values['role'] ?? null);
        $this->assertSame('sso_mapping', $audit->new_values['source'] ?? null);
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

    /** Pegawai yang sudah di-soft-delete tidak boleh dipetakan menjadi akun SSO baru. */
    public function test_soft_deleted_employee_cannot_match_keycloak_login(): void
    {
        $trashed = Employee::factory()->create([
            'nama_lengkap' => 'Nonaktif',
            'email' => 'nonaktif@example.com',
        ]);
        $trashed->delete();

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
    public function test_role_not_initialized_for_account_of_soft_deleted_employee(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Nonaktif Terpeta',
            'email' => 'softdel-account@example.com',
        ]);
        $user = User::factory()->create([
            'email' => 'softdel-account@example.com',
            'keycloak_id' => 'kc-softdel-account',
            'employee_id' => $employee->id,
            'role' => null,
        ]);
        $employee->delete();

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

    public function test_database_seeder_creates_demo_role_users(): void
    {
        // Setelah merge development, DatabaseSeeder memanggil DemoSsoUserSeeder + SsoRoleMappedAccountSeeder
        // sehingga akun demo tetap tersedia untuk PhaseSevenBrowserQaSeeder, bersama mapping SSO.
        $this->seed(DatabaseSeeder::class);

        foreach (['demo-klabat', 'demo-klabat-kepeg', 'demo-klabat-kabag', 'demo-klabat-pimpinan', 'demo-klabat-pegawai'] as $username) {
            $this->assertNotNull(User::where('keycloak_username', $username)->first(), "Demo user {$username} harus ada untuk fixture browser QA.");
        }

        foreach ((array) config('services.keycloak.role_mapping', []) as $email => $role) {
            $this->assertDatabaseHas('users', [
                'email' => $email,
                'role' => $role,
            ]);
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

    /** Pegawai berstatus Non-Aktif (tanpa soft-delete) tidak boleh mendapat role baru via SSO. */
    public function test_role_not_initialized_for_non_active_employee_without_soft_delete(): void
    {
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pensiunan Belum Dihapus',
            'email' => 'pensiun@example.com',
            'status_aktif' => 'Pensiun',
        ]);

        // User terpeta tanpa soft-delete, role masih kosong.
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
