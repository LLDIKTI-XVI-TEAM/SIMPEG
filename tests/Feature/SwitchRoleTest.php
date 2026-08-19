<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\RefUnitKerja;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwitchRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function createUserWithRole(string $role): User
    {
        $employee = Employee::factory()->create();

        return User::factory()->create([
            'email' => "test-{$role}-".uniqid().'@example.com',
            'role' => $role,
            'employee_id' => $employee->id,
        ]);
    }

    public function test_super_admin_can_switch_to_lower_role(): void
    {
        $user = $this->createUserWithRole('super_admin');

        $response = $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'admin_kepegawaian',
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('admin_kepegawaian', $user->temporary_role);
        $this->assertEquals('super_admin', $user->role);
        $this->assertNotNull($user->temporary_role_started_at);
        $this->assertEquals($user->id, $user->temporary_role_switched_by);
    }

    public function test_super_admin_cannot_switch_to_same_role(): void
    {
        $user = $this->createUserWithRole('super_admin');

        $response = $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'super_admin',
        ]);

        $response->assertSessionHasErrors('target_role');
        $user->refresh();
        $this->assertNull($user->temporary_role);
    }

    public function test_admin_kepegawaian_cannot_switch_role(): void
    {
        $user = $this->createUserWithRole('admin_kepegawaian');

        $response = $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
        ]);

        $response->assertStatus(403);
        $user->refresh();
        $this->assertNull($user->temporary_role);
    }

    public function test_revert_role_returns_to_original(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Switch dulu
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'admin_kepegawaian',
        ]);

        $user->refresh();
        $this->assertEquals('admin_kepegawaian', $user->temporary_role);

        // Revert
        $response = $this->actingAs($user)->post(route('revert-role'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertNull($user->temporary_role);
        $this->assertNull($user->temporary_permission);
        $this->assertNull($user->temporary_role_started_at);
        $this->assertNull($user->temporary_role_switched_by);
    }

    public function test_revert_role_when_not_switched_is_noop(): void
    {
        $user = $this->createUserWithRole('admin_kepegawaian');

        $response = $this->actingAs($user)->post(route('revert-role'));

        $response->assertRedirect(route('dashboard'));
        $user->refresh();
        $this->assertNull($user->temporary_role);
    }

    public function test_effective_role_returns_temporary_when_switched(): void
    {
        $user = $this->createUserWithRole('super_admin');
        $user->forceFill([
            'temporary_role' => 'pegawai',
            'temporary_role_started_at' => now(),
        ])->save();

        $user->refresh();
        $this->assertEquals('pegawai', $user->getEffectiveRole());
        $this->assertEquals('super_admin', $user->role);
    }

    public function test_has_permission_uses_effective_role_when_switched(): void
    {
        $user = $this->createUserWithRole('super_admin');
        $user->forceFill([
            'temporary_role' => 'pegawai',
            'temporary_role_started_at' => now(),
        ])->save();

        $user->refresh();

        // Role asli super_admin memiliki semua permission, tapi saat switch ke pegawai,
        // hanya permission pegawai yang berlaku
        $this->assertFalse($user->hasPermission('users.switch_role'));
        $this->assertTrue($user->hasPermission('employees.read_self'));
    }

    public function test_audit_log_records_switch_and_revert(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Switch
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pimpinan',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'SWITCH_ROLE',
            'auditable_type' => 'User',
            'auditable_id' => $user->id,
            'user_id' => $user->id,
        ]);

        // Revert
        $this->actingAs($user)->post(route('revert-role'));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'REVERT_ROLE',
            'auditable_type' => 'User',
            'auditable_id' => $user->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_switch_role_invalid_target_is_rejected(): void
    {
        $user = $this->createUserWithRole('super_admin');

        $response = $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'nonexistent_role',
        ]);

        $response->assertSessionHasErrors('target_role');
        $user->refresh();
        $this->assertNull($user->temporary_role);
    }

    public function test_switch_role_persists_across_requests(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Switch role
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'kepala_bagian',
        ]);

        $user->refresh();
        $this->assertEquals('kepala_bagian', $user->temporary_role);

        // Request lain masih menunjukkan temporary_role, route dashboard me-redirect kepala_bagian ke kepala-bagian.dashboard
        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertRedirect(route('kepala-bagian.dashboard'));

        $user->refresh();
        $this->assertEquals('kepala_bagian', $user->temporary_role);
    }

    public function test_switch_role_persists_across_logout_and_login(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Switch role ke pegawai
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
        ]);

        $user->refresh();
        $this->assertEquals('pegawai', $user->temporary_role);

        // Simulasi logout (flush session auth)
        $this->post(route('logout'));
        $this->assertGuest();

        // Login kembali dengan user yang sama
        $this->actingAs($user);
        $this->assertAuthenticatedAs($user);

        // State simulasi tetap tersimpan persisten di database
        $user->refresh();
        $this->assertEquals('pegawai', $user->temporary_role);
        $this->assertEquals('pegawai', $user->getEffectiveRole());
    }

    public function test_switched_super_admin_cannot_switch_again_due_to_effective_permission(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Switch ke pegawai
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
        ]);

        $user->refresh();
        $this->assertEquals('pegawai', $user->getEffectiveRole());

        // Coba switch lagi saat mode pegawai -> harus 403 Forbidden karena role pegawai tidak punya users.switch_role
        $response = $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'admin_kepegawaian',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_ownership_scope_and_identity_preserved_during_simulation(): void
    {
        $user = $this->createUserWithRole('super_admin');
        $originalEmployeeId = $user->employee_id;
        $originalName = $user->name;

        // Switch role ke pegawai
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
        ]);

        $user->refresh();

        // Identitas asli tidak berubah
        $this->assertEquals($originalEmployeeId, $user->employee_id);
        $this->assertEquals($originalName, $user->name);
        $this->assertEquals('super_admin', $user->role);
        $this->assertEquals('pegawai', $user->temporary_role);

        // Akses data sendiri (read_self) tetap valid untuk employee asli
        $this->assertTrue($user->hasPermission('employees.read_self'));
        $this->assertFalse($user->hasPermission('employees.create'));
    }

    public function test_temporary_permission_is_metadata_and_permissions_stay_dynamic(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Switch dengan temporary_permission hanya metadata; otorisasi tidak boleh
        // dibatasi maupun diganti oleh snapshot tersebut.
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
            'temporary_permission' => json_encode(['employees.read_self']),
        ]);

        $user->refresh();
        $this->assertEquals('pegawai', $user->temporary_role);
        $this->assertNotNull($user->temporary_permission);

        // Permission milik role tujuan tetap berlaku meskipun tidak tercantum di snapshot.
        $this->assertTrue($user->hasPermission('employees.read_self'));
        $this->assertTrue($user->hasPermission('employee_histories.read'));

        // Permission di luar hak role tujuan tetap false.
        $this->assertFalse($user->hasPermission('employees.create'));

        // Revert membersihkan metadata temporary_permission juga.
        $this->actingAs($user)->post(route('revert-role'));
        $user->refresh();
        $this->assertNull($user->temporary_permission);
        $this->assertNull($user->temporary_role);
    }

    public function test_switch_role_accepts_temporary_permission_as_inert_metadata(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Metadata boleh memuat nama permission apa pun; ia tidak pernah memberi otorisasi.
        $response = $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
            'temporary_permission' => json_encode(['audit_logs.read']),
        ]);

        $response->assertRedirect(route('dashboard'));
        $user->refresh();
        $this->assertEquals('pegawai', $user->temporary_role);
        $this->assertNotNull($user->temporary_permission);

        // audit_logs.read tetap false karena role tujuan (pegawai) tidak memilikinya.
        $this->assertFalse($user->hasPermission('audit_logs.read'));
    }

    public function test_switch_role_rejects_overlong_temporary_permission(): void
    {
        $user = $this->createUserWithRole('super_admin');

        $response = $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
            'temporary_permission' => str_repeat('x', 2001),
        ]);

        $response->assertSessionHasErrors('temporary_permission');
        $user->refresh();
        $this->assertNull($user->temporary_role);
    }

    public function test_simulation_cancelled_by_mapping_change_logs_revert_role(): void
    {
        $actor = $this->createUserWithRole('super_admin');
        $target = $this->createUserWithRole('super_admin');
        $target->forceFill(['keycloak_id' => 'kc-simulation-cancel'])->save();

        // Target switch ke admin_kepegawaian
        $this->actingAs($target)->post(route('switch-role'), [
            'target_role' => 'admin_kepegawaian',
        ]);

        $target->refresh();
        $this->assertEquals('admin_kepegawaian', $target->temporary_role);

        // Admin lain memetakan ulang target menjadi pegawai -> simulasi gugur
        $this->actingAs($actor)->post(route('user-management.update'), [
            'employee_id' => $target->employee_id,
            'keycloak_id' => $target->keycloak_id,
            'role' => 'pegawai',
        ])->assertRedirect();

        $target->refresh();
        $this->assertEquals('pegawai', $target->role);
        $this->assertNull($target->temporary_role);
        $this->assertNull($target->temporary_permission);

        // Pembatalan simulasi tercatat sebagai REVERT_ROLE dengan snapshot temporary_permission
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'REVERT_ROLE',
            'auditable_type' => 'User',
            'auditable_id' => $target->id,
        ]);
    }

    public function test_audit_logs_record_simulation_context_during_active_temporary_role(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Switch role
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
        ]);

        $user->refresh();

        // Aksi yang memicu audit selama simulasi
        AuditService::log(
            'UPDATE',
            'Employee',
            $user->employee_id,
            ['keterangan' => 'lama'],
            ['keterangan' => 'baru'],
        );

        $audit = AuditLog::where('event', 'UPDATE')
            ->where('auditable_type', 'Employee')
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($audit);
        $this->assertTrue($audit->new_values['_simulation'] ?? false);
        $this->assertEquals('super_admin', $audit->new_values['_original_role'] ?? null);
        $this->assertEquals('pegawai', $audit->new_values['_effective_role'] ?? null);
    }

    public function test_audit_explicit_path_carries_simulation_context(): void
    {
        $user = $this->createUserWithRole('super_admin');

        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
        ]);

        $user->refresh();

        // Jalur aktor eksplisit (dipakai LOGIN/LOGOUT/session timeout) juga wajib
        // membawa konteks simulasi agar jejak audit tetap dapat ditelusuri.
        AuditService::logAsOrFail(
            $user->id,
            $user->name,
            'LOGOUT',
            'User',
            $user->id,
            null,
            ['catatan' => 'keluar saat simulasi aktif'],
        );

        $audit = AuditLog::where('event', 'LOGOUT')
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($audit);
        $this->assertTrue($audit->new_values['_simulation'] ?? false);
        $this->assertEquals('super_admin', $audit->new_values['_original_role'] ?? null);
        $this->assertEquals('pegawai', $audit->new_values['_effective_role'] ?? null);
    }

    public function test_dashboard_and_requests_use_effective_role_during_simulation(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Switch role ke pimpinan
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pimpinan',
        ]);

        $user->refresh();

        // Dashboard request harus mengarahkan ke dashboard pimpinan
        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertRedirect(route('pimpinan.dashboard'));

        // FormRequest filter pimpinan harus mengotorisasi request
        $filterResponse = $this->actingAs($user)->get(route('pimpinan.laporan.kepangkatan'));
        $filterResponse->assertOk();
    }

    public function test_simulation_is_cancelled_if_account_role_is_demoted(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Switch role ke admin_kepegawaian
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'admin_kepegawaian',
        ]);

        $user->refresh();
        $this->assertEquals('admin_kepegawaian', $user->temporary_role);
        $this->assertEquals('admin_kepegawaian', $user->getEffectiveRole());

        // Akun asli diubah / didemosi menjadi pegawai
        $user->role = 'pegawai';
        $user->save();

        // Accessor getEffectiveRole harus menolak temporary_role admin_kepegawaian karena lebih tinggi dari role pegawai
        $this->assertEquals('pegawai', $user->getEffectiveRole());
    }

    public function test_switch_role_array_payload_returns_validation_error_not_500(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Kirim target_role sebagai array
        $response = $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => ['pegawai'],
        ]);

        $response->assertSessionHasErrors('target_role');
        $user->refresh();
        $this->assertNull($user->temporary_role);
    }

    public function test_temporary_permission_is_no_longer_effective_after_revoked_from_role(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Switch ke admin_kepegawaian dengan snapshot employees.update (dimiliki role saat itu).
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'admin_kepegawaian',
            'temporary_permission' => json_encode(['employees.update']),
        ]);

        $user->refresh();
        $this->assertTrue($user->hasPermission('employees.update'));

        // Permission dicabut dari role target melalui konfigurasi RBAC.
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permission = Permission::where('name', 'employees.update')->firstOrFail();
        $role->permissions()->detach($permission);

        // Snapshot lama tidak boleh lagi dianggap otoritatif: re-validasi RBAC saat runtime.
        $user->refresh();
        $this->assertFalse($user->hasPermission('employees.update'));

        // Level HTTP: route yang digate permission:employees.update harus ditolak.
        $response = $this->actingAs($user)->post(route('pegawai.status.update'), [
            'employee_id' => $user->employee_id,
            'status' => 'aktif',
        ]);

        $response->assertForbidden();
    }

    public function test_permission_added_to_target_role_after_switch_applies_on_next_request(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Switch ke pegawai dengan snapshot lama yang belum memuat employees.read.
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
            'temporary_permission' => json_encode(['employees.read_self']),
        ]);

        $user->refresh();
        $this->assertFalse($user->hasPermission('employees.read'));

        // Permission ditambahkan ke role target SETELAH switch (keputusan RBAC baru).
        $pegawaiRole = Role::where('name', 'pegawai')->firstOrFail();
        $readPermission = Permission::where('name', 'employees.read')->firstOrFail();
        $pegawaiRole->permissions()->syncWithoutDetaching([$readPermission->id]);

        // Request berikutnya langsung menikmati permission baru — snapshot tidak membatasi.
        $user->refresh();
        $this->assertTrue($user->hasPermission('employees.read'));
    }

    public function test_switch_role_only_allowed_for_super_admin_origin(): void
    {
        // users.switch_role sengaja dipasang ke role non-Super-Admin (salah konfigurasi).
        $adminRole = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $switchPermission = Permission::where('name', 'users.switch_role')->firstOrFail();
        $adminRole->permissions()->syncWithoutDetaching([$switchPermission->id]);

        $admin = $this->createUserWithRole('admin_kepegawaian');
        $this->assertTrue($admin->hasPermission('users.switch_role'));

        // Invariant source role: admin_kepegawaian tetap ditolak walaupun punya permission.
        $response = $this->actingAs($admin)->post(route('switch-role'), [
            'target_role' => 'pegawai',
        ]);

        $response->assertForbidden();
        $admin->refresh();
        $this->assertNull($admin->temporary_role);
    }

    public function test_cuti_create_button_uses_effective_role_during_pegawai_simulation(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Super Admin biasa: tombol Ajukan Cuti Baru tidak tampil.
        $this->actingAs($user)
            ->get(route('cuti'))
            ->assertOk()
            ->assertDontSee('Ajukan Cuti Baru');

        // Simulasi pegawai: role efektif pegawai memenuhi syarat cuti.create,
        // tombol harus tampil meskipun role asli tetap super_admin.
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
        ]);

        $user->refresh();
        $this->assertEquals('pegawai', $user->getEffectiveRole());
        $this->assertTrue($user->hasPermission('cuti.create'));

        $this->actingAs($user)
            ->get(route('cuti'))
            ->assertOk()
            ->assertSee('Ajukan Cuti Baru');
    }

    public function test_switch_to_unregistered_target_blocks_requests_but_revert_still_works(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Role target dihapus / tidak terdaftar di tabel roles (validasi switch hanya
        // memakai whitelist string + hierarki keras, tidak mengecek keberadaan record role).
        Role::where('name', 'pimpinan')->delete();

        // Switch tetap berhasil menyimpan state simulasi.
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pimpinan',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertEquals('pimpinan', $user->temporary_role);
        $this->assertEquals('pimpinan', $user->getEffectiveRole());

        // Semua request lain ditolak EnsureRole karena role efektif tidak terdaftar di tabel roles.
        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();

        // Jalur pemulihan revert wajib tetap berjalan walau state simulasi tidak valid.
        $response = $this->actingAs($user)->post(route('revert-role'));
        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertNull($user->temporary_role);
        $this->assertNull($user->temporary_permission);
        $this->assertNull($user->temporary_role_started_at);
        $this->assertNull($user->temporary_role_switched_by);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'REVERT_ROLE',
            'auditable_type' => 'User',
            'auditable_id' => $user->id,
        ]);
    }

    public function test_global_search_uses_effective_role_for_pimpinan_simulation(): void
    {
        $haystack = 'xXSearchTargetXx';

        $user = $this->createUserWithRole('super_admin');

        $employee = Employee::factory()->create(['nama_lengkap' => $haystack.' Pegawai']);
        RefUnitKerja::create(['nama' => $haystack.' Unit']);
        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => $haystack.' Dokumen',
            'file_path' => 'test.pdf',
        ]);
        User::factory()->create([
            'name' => $haystack.' Pengguna',
            'email' => 'pengguna-'.$haystack.'@example.com',
            'role' => 'pegawai',
        ]);

        // Mode admin (belum simulasi): seluruh section hasil muncul.
        $adminJson = $this->actingAs($user)
            ->getJson(route('global.search').'?q='.$haystack)
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('Pegawai', $adminJson);
        $this->assertArrayHasKey('Unit Kerja', $adminJson);
        $this->assertArrayHasKey('Dokumen', $adminJson);
        $this->assertArrayHasKey('Pengguna Sistem', $adminJson);
        $this->assertStringNotContainsString('/pimpinan/', (string) $adminJson['Pegawai'][0]['url']);

        // Simulasi pimpinan.
        $this->actingAs($user)->post(route('switch-role'), ['target_role' => 'pimpinan']);
        $user->refresh();
        $this->assertEquals('pimpinan', $user->getEffectiveRole());

        // Mode pimpinan: hanya hasil khusus pimpinan, tanpa unit kerja/dokumen/pengguna sistem.
        $pimpinanJson = $this->actingAs($user)
            ->getJson(route('global.search').'?q='.$haystack)
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('Pegawai', $pimpinanJson);
        $this->assertArrayNotHasKey('Unit Kerja', $pimpinanJson);
        $this->assertArrayNotHasKey('Dokumen', $pimpinanJson);
        $this->assertArrayNotHasKey('Pengguna Sistem', $pimpinanJson);

        // URL hasil pegawai harus menunjuk route namespace pimpinan.
        $this->assertStringContainsString('/pimpinan/', (string) $pimpinanJson['Pegawai'][0]['url']);
    }

    public function test_async_explicit_audit_resolves_simulation_context_from_actor_id(): void
    {
        $user = $this->createUserWithRole('super_admin');
        $user->forceFill([
            'temporary_role' => 'admin_kepegawaian',
            'temporary_role_started_at' => now(),
        ])->save();

        // Tanpa actingAs: Auth::user() = null (menyerupai worker queue), padahal state
        // temporary_role persisten di database. Konteks harus di-resolve dari $userId.
        AuditService::logAsOrFail(
            $user->id,
            $user->name,
            'IMPORT',
            'User',
            $user->id,
            null,
            ['batch' => 'stub'],
        );

        $audit = AuditLog::where('event', 'IMPORT')
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($audit);
        $this->assertTrue($audit->new_values['_simulation'] ?? false);
        $this->assertEquals('super_admin', $audit->new_values['_original_role'] ?? null);
        $this->assertEquals('admin_kepegawaian', $audit->new_values['_effective_role'] ?? null);
    }

    /** Submenu switch disembunyikan saat simulasi aktif agar UI tidak menyesatkan; revert tetap tampil. */
    public function test_switch_menu_hidden_during_active_simulation(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Tanpa simulasi: Super Admin melihat submenu "Simulasi Role" + aksi switch.
        $this->actingAs($user)
            ->get(route('cuti'))
            ->assertOk()
            ->assertSee('Simulasi Role')
            ->assertSee('Switch ke Pegawai');

        // Aktifkan simulasi role pegawai: role efektif menurun sehingga submenu switch
        // tidak lagi dirender (guard eksplisit + permission efektif), hanya revert yang tampil.
        $this->actingAs($user)->post(route('switch-role'), ['target_role' => 'pegawai']);

        $user->refresh();

        $response = $this->actingAs($user)->get(route('cuti'));
        $response->assertOk();
        $response->assertDontSee('Simulasi Role');
        $response->assertDontSee('Switch ke');
        $response->assertSee('Kembalikan Role Asli');
    }
}
