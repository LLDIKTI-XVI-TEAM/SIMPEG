<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
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

        // Request lain masih menunjukkan temporary_role
        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        $user->refresh();
        $this->assertEquals('kepala_bagian', $user->temporary_role);
    }
}
