<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
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

    public function test_temporary_permission_is_stored_and_evaluated_correctly(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // Switch dengan temporary_permission yang merupakan subset hak role pegawai
        $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
            'temporary_permission' => json_encode(['employees.read_self', 'employee_histories.read']),
        ]);

        $user->refresh();
        $this->assertEquals('pegawai', $user->temporary_role);
        $this->assertNotNull($user->temporary_permission);

        // Permission yang terdaftar di temporary_permission harus true
        $this->assertTrue($user->hasPermission('employee_histories.read'));
        $this->assertTrue($user->hasPermission('employees.read_self'));

        // Permission di luar temporary_permission harus false
        $this->assertFalse($user->hasPermission('employees.create'));

        // Revert harus membersihkan temporary_permission juga
        $this->actingAs($user)->post(route('revert-role'));
        $user->refresh();
        $this->assertNull($user->temporary_permission);
        $this->assertNull($user->temporary_role);
    }

    public function test_switch_role_rejects_temporary_permission_outside_target_role(): void
    {
        $user = $this->createUserWithRole('super_admin');

        // cuti.read_all bukan milik role pegawai -> harus ditolak oleh validasi server-side
        $response = $this->actingAs($user)->post(route('switch-role'), [
            'target_role' => 'pegawai',
            'temporary_permission' => json_encode(['cuti.read_all']),
        ]);

        $response->assertSessionHasErrors('temporary_permission');
        $user->refresh();
        $this->assertNull($user->temporary_role);
        $this->assertNull($user->temporary_permission);
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
}
