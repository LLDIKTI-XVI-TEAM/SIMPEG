<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserMappingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    // -----------------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------------

    public function test_guest_cannot_access_user_mapping(): void
    {
        $this->get(route('user-management'))
            ->assertRedirect();
    }

    public function test_non_super_admin_cannot_access_user_mapping(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('user-management'))
            ->assertForbidden();
    }

    public function test_super_admin_can_access_user_mapping_index(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('user-management'))
            ->assertOk();
    }

    // -----------------------------------------------------------------------
    // Update — happy path
    // -----------------------------------------------------------------------

    public function test_update_saves_role_and_keycloak_id(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['email' => 'andi@example.com']);
        $user = User::factory()->create(['email' => 'andi@example.com', 'role' => 'pegawai']);

        $this->actingAs($admin)
            ->post(route('user-management.update'), [
                'email' => 'andi@example.com',
                'keycloak_id' => 'kc-uuid-123',
                'role' => 'admin_kepegawaian',
            ])
            ->assertRedirect();

        $user->refresh();
        $this->assertSame('kc-uuid-123', $user->keycloak_id);
        $this->assertSame('admin_kepegawaian', $user->role);
    }

    public function test_update_saves_employee_id_when_employee_email_matches(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['email' => 'budi@example.com']);
        $user = User::factory()->create(['email' => 'budi@example.com', 'employee_id' => null]);

        $this->actingAs($admin)
            ->post(route('user-management.update'), [
                'email' => 'budi@example.com',
                'keycloak_id' => '',
                'role' => 'kepala_bagian',
            ])
            ->assertRedirect();

        $user->refresh();
        $this->assertSame($employee->id, $user->employee_id);
    }

    public function test_update_writes_audit_log_to_database(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create(['email' => 'citra@example.com', 'role' => 'pegawai']);

        $this->actingAs($admin)
            ->post(route('user-management.update'), [
                'email' => 'citra@example.com',
                'keycloak_id' => '',
                'role' => 'pimpinan',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'User',
            'auditable_id' => $user->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Update — validation
    // -----------------------------------------------------------------------

    public function test_update_rejects_missing_email(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('user-management.update'), [
                'keycloak_id' => '',
                'role' => 'pegawai',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_update_rejects_invalid_role(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('user-management.update'), [
                'email' => 'x@example.com',
                'keycloak_id' => '',
                'role' => 'invalid_role',
            ])
            ->assertSessionHasErrors('role');
    }

    // -----------------------------------------------------------------------
    // Update — uniqueness enforcement
    // -----------------------------------------------------------------------

    public function test_update_rejects_duplicate_keycloak_id_on_different_user(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $existingUser = User::factory()->create([
            'email' => 'first@example.com',
            'keycloak_id' => 'kc-taken',
        ]);

        $this->actingAs($admin)
            ->post(route('user-management.update'), [
                'email' => 'second@example.com',
                'keycloak_id' => 'kc-taken',
                'role' => 'pegawai',
            ])
            ->assertSessionHas('error');
    }
}
