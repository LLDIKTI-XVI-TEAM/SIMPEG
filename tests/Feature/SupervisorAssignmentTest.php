<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupervisorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_assign_supervisor(): void
    {
        $employee = Employee::factory()->create();
        $supervisor = Employee::factory()->create();

        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'supervisor_id' => $supervisor->id,
        ]);

        $response->assertRedirect('/login');
    }

    public function test_pegawai_cannot_assign_supervisor(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = Employee::factory()->create();
        $supervisor = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'supervisor_id' => $supervisor->id,
        ]);

        $response->assertForbidden();
    }

    public function test_admin_kepegawaian_cannot_assign_supervisor(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $supervisor = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'supervisor_id' => $supervisor->id,
        ]);

        $response->assertForbidden();
    }

    public function test_super_admin_can_assign_supervisor(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $supervisor = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => $supervisor->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Kepala bagian berhasil diperbarui.');
        $response->assertJsonPath('employee.kepala_bagian.id', $supervisor->id);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'kepala_bagian_id' => $supervisor->id,
            'atasan_langsung_id' => $supervisor->id,
        ]);

        $this->assertDatabaseHas('supervisor_assignments', [
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisor->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_berakhir' => null,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_super_admin_can_clear_supervisor(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => null,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'kepala_bagian_id' => null,
            'atasan_langsung_id' => null,
        ]);
    }

    public function test_supervisor_cannot_be_self(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => $employee->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['kepala_bagian_id']);
    }

    public function test_supervisor_change_closes_old_assignment(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $supervisor1 = Employee::factory()->create();
        $supervisor2 = Employee::factory()->create();

        $this->actingAs($user);

        // First assignment
        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => $supervisor1->id,
        ]);

        // Second assignment
        $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => $supervisor2->id,
        ]);

        $this->assertDatabaseHas('supervisor_assignments', [
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisor1->id,
            'supervisor_id' => $supervisor1->id,
            'tanggal_berakhir' => now()->startOfDay()->toDateTimeString(),
        ]);

        $this->assertDatabaseHas('supervisor_assignments', [
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisor2->id,
            'supervisor_id' => $supervisor2->id,
            'tanggal_berakhir' => null,
        ]);
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
