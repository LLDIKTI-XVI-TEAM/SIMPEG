<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Employees\KepalaBagianScopeService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupervisorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-23 08:00:00');
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    public static function authorizedRoleProvider(): array
    {
        return [
            'admin kepegawaian' => ['adminKepegawaian'],
            'super admin' => ['superAdmin'],
        ];
    }

    #[DataProvider('authorizedRoleProvider')]
    public function test_authorized_role_can_assign_effective_dated_supervisor(string $factoryState): void
    {
        $user = User::factory()->{$factoryState}()->create();
        $employee = Employee::factory()->create();
        $supervisor = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => $supervisor->id,
            'effective_date' => '2026-07-20',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('supervisor_assignments', [
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => '2026-07-20 00:00:00',
            'tanggal_berakhir' => null,
        ]);
    }

    public function test_super_admin_can_assign_supervisor(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $supervisor = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => $supervisor->id,
            'effective_date' => '2026-07-23',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Kepala bagian berhasil diperbarui.');
        $response->assertJsonPath('employee.kepala_bagian.id', $supervisor->id);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'kepala_bagian_id' => $supervisor->id,
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
            'effective_date' => '2026-07-23',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'kepala_bagian_id' => null,
        ]);
    }

    public function test_supervisor_cannot_be_self(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => $employee->id,
            'effective_date' => '2026-08-01',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['kepala_bagian_id']);
    }

    public function test_soft_deleted_supervisor_is_rejected_without_mutation(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $supervisor = Employee::factory()->create();
        $supervisor->delete();

        $response = $this->actingAs($user)->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => $supervisor->id,
            'effective_date' => '2026-07-23',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['kepala_bagian_id']);
        $this->assertDatabaseCount('supervisor_assignments', 0);
        $this->assertNull($employee->fresh()->kepala_bagian_id);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_soft_deleted_legacy_supervisor_is_rejected_on_legacy_field_without_mutation(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $supervisor = Employee::factory()->create();
        $supervisor->delete();

        $response = $this->actingAs($user)->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'supervisor_id' => $supervisor->id,
            'effective_date' => '2026-07-23',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['supervisor_id']);
        $this->assertDatabaseCount('supervisor_assignments', 0);
        $this->assertNull($employee->fresh()->kepala_bagian_id);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_hard_audit_propagates_audit_log_persistence_failure(): void
    {
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::creating(function (): void {
            throw new \RuntimeException('Simulasi kegagalan persistensi hard audit.');
        });
        $exceptionObserved = false;

        try {
            app(AuditService::class)->logOrFail('UPDATE', 'Employee');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan persistensi hard audit.', $exception->getMessage());
            $exceptionObserved = true;
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertTrue($exceptionObserved, 'Hard audit wajib meneruskan kegagalan persistensi.');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidEffectiveDateProvider(): array
    {
        return [
            'tidak dikirim' => [null],
            'format bukan Y-m-d' => ['23/07/2026'],
        ];
    }

    #[DataProvider('invalidEffectiveDateProvider')]
    public function test_effective_date_is_required_and_must_use_y_m_d_without_mutation(?string $effectiveDate): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['kepala_bagian_id' => null]);
        $supervisor = Employee::factory()->create();
        $payload = ['kepala_bagian_id' => $supervisor->id];

        if ($effectiveDate !== null) {
            $payload['effective_date'] = $effectiveDate;
        }

        $response = $this->actingAs($user)->postJsonWithCsrf(
            "/api/v1/pegawai/{$employee->id}/assign-atasan",
            $payload,
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['effective_date']);
        $this->assertDatabaseCount('supervisor_assignments', 0);
        $this->assertNull($employee->fresh()->kepala_bagian_id);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_backdated_assignment_closes_previous_on_inclusive_h_minus_one_and_is_bounded_by_next_future_assignment(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $supervisorLama = Employee::factory()->create();
        $supervisorBaru = Employee::factory()->create();
        $supervisorMendatang = Employee::factory()->create();

        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisorLama->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisorMendatang->id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_berakhir' => null,
        ]);
        $employee->update(['kepala_bagian_id' => $supervisorLama->id]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => $supervisorBaru->id,
            'effective_date' => '2026-07-20',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('supervisor_assignments', [
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisorLama->id,
            'tanggal_mulai' => '2026-01-01 00:00:00',
            'tanggal_berakhir' => '2026-07-19 00:00:00',
        ]);
        $this->assertDatabaseHas('supervisor_assignments', [
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisorBaru->id,
            'tanggal_mulai' => '2026-07-20 00:00:00',
            'tanggal_berakhir' => '2026-08-31 00:00:00',
        ]);
        $this->assertDatabaseHas('supervisor_assignments', [
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisorMendatang->id,
            'tanggal_mulai' => '2026-09-01 00:00:00',
            'tanggal_berakhir' => null,
        ]);
        $this->assertSupervisorAssignmentsDoNotOverlap($employee);
    }

    public function test_same_date_same_supervisor_is_no_op_without_extra_history_audit_or_chain_revision(): void
    {
        $user = User::factory()->superAdmin()->create();
        $supervisor = Employee::factory()->create();
        $employee = Employee::factory()->create(['kepala_bagian_id' => $supervisor->id]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => '2026-07-23',
            'tanggal_berakhir' => null,
        ]);
        $chain = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Chain aktif',
            'effective_from' => '2026-01-01',
        ]);
        $chain->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $supervisor->id,
            'is_final' => false,
        ]);
        $auditCount = AuditLog::count();
        $chainUpdatedAt = $chain->updated_at;

        $response = $this->actingAs($user)->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => $supervisor->id,
            'effective_date' => '2026-07-23',
        ]);

        $response->assertOk();
        $this->assertSame(1, SupervisorAssignment::where('employee_id', $employee->id)->count());
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertTrue($chainUpdatedAt->equalTo($chain->fresh()->updated_at));
    }

    public function test_same_date_different_supervisor_updates_existing_row_instead_of_adding_history(): void
    {
        $user = User::factory()->superAdmin()->create();
        $supervisorLama = Employee::factory()->create();
        $supervisorBaru = Employee::factory()->create();
        $employee = Employee::factory()->create(['kepala_bagian_id' => $supervisorLama->id]);
        $assignment = SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisorLama->id,
            'tanggal_mulai' => '2026-07-23',
            'tanggal_berakhir' => null,
        ]);

        $response = $this->actingAs($user)->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => $supervisorBaru->id,
            'effective_date' => '2026-07-23',
        ]);

        $response->assertOk();
        $this->assertSame(1, SupervisorAssignment::where('employee_id', $employee->id)->count());
        $this->assertDatabaseHas('supervisor_assignments', [
            'id' => $assignment->id,
            'kepala_bagian_id' => $supervisorBaru->id,
            'tanggal_mulai' => '2026-07-23 00:00:00',
            'tanggal_berakhir' => null,
        ]);
    }

    public function test_future_assignment_does_not_change_today_pointer_current_resolver_or_direct_report_scope(): void
    {
        $user = User::factory()->superAdmin()->create();
        $supervisorSaatIni = Employee::factory()->create();
        $supervisorMendatang = Employee::factory()->create();
        $employee = Employee::factory()->create(['kepala_bagian_id' => $supervisorSaatIni->id]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisorSaatIni->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);
        $chain = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Chain aktif',
            'effective_from' => '2026-01-01',
        ]);
        $step = $chain->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $supervisorSaatIni->id,
            'is_final' => false,
        ]);
        $userSaatIni = User::factory()->kepalaBagian()->create(['employee_id' => $supervisorSaatIni->id]);
        $userMendatang = User::factory()->kepalaBagian()->create(['employee_id' => $supervisorMendatang->id]);

        $response = $this->actingAs($user)->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
            'kepala_bagian_id' => $supervisorMendatang->id,
            'effective_date' => '2026-08-01',
        ]);

        $response->assertOk();
        $this->assertSame($supervisorSaatIni->id, $employee->fresh()->kepala_bagian_id);
        $this->assertSame($supervisorSaatIni->id, $employee->fresh()->currentSupervisor()?->kepala_bagian_id);
        $scope = app(KepalaBagianScopeService::class);
        $this->assertTrue($scope->hasDirectReport($userSaatIni, $employee->id));
        $this->assertFalse($scope->hasDirectReport($userMendatang, $employee->id));
        $this->assertSame($supervisorSaatIni->id, $step->fresh()->approver_employee_id);
    }

    public function test_assignment_rolls_back_history_pointer_audit_and_chain_when_chain_sync_fails(): void
    {
        $user = User::factory()->superAdmin()->create();
        $supervisorLama = Employee::factory()->create();
        $supervisorBaru = Employee::factory()->create();
        $employee = Employee::factory()->create(['kepala_bagian_id' => $supervisorLama->id]);
        $assignment = SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $supervisorLama->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);
        $chain = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Chain aktif',
            'effective_from' => '2026-01-01',
        ]);
        $step = $chain->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $supervisorLama->id,
            'is_final' => false,
        ]);
        $auditCount = AuditLog::count();
        $fake = new class extends AuditService
        {
            public static function logOrFail(
                string $event,
                string $auditableType,
                ?string $auditableId = null,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?Request $request = null,
                ?string $ipAddress = null,
                ?string $userAgent = null,
            ): void {
                throw new \RuntimeException('Simulasi kegagalan audit penugasan Kepala Bagian.');
            }
        };
        $this->app->instance(AuditService::class, $fake);
        $exceptionObserved = false;

        try {
            $this->actingAs($user)->withoutExceptionHandling()->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/assign-atasan", [
                'kepala_bagian_id' => $supervisorBaru->id,
                'effective_date' => '2026-07-23',
            ]);
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan audit penugasan Kepala Bagian.', $exception->getMessage());
            $exceptionObserved = true;
        }

        $this->assertTrue($exceptionObserved, 'Mutasi harus menulis audit melalui service yang terikat di container.');
        $this->assertDatabaseHas('supervisor_assignments', [
            'id' => $assignment->id,
            'kepala_bagian_id' => $supervisorLama->id,
            'tanggal_berakhir' => null,
        ]);
        $this->assertSame(1, SupervisorAssignment::where('employee_id', $employee->id)->count());
        $this->assertSame($supervisorLama->id, $employee->fresh()->kepala_bagian_id);
        $this->assertSame($supervisorLama->id, $step->fresh()->approver_employee_id);
        $this->assertSame($auditCount, AuditLog::count());
    }

    private function assertSupervisorAssignmentsDoNotOverlap(Employee $employee): void
    {
        $assignments = SupervisorAssignment::query()
            ->where('employee_id', $employee->id)
            ->orderBy('tanggal_mulai')
            ->get();

        for ($index = 1; $index < $assignments->count(); $index++) {
            $current = $assignments[$index - 1];
            $next = $assignments[$index];
            $this->assertNotNull($current->tanggal_berakhir);
            $this->assertTrue(
                $current->tanggal_berakhir->lt($next->tanggal_mulai),
                'Rentang penugasan Kepala Bagian inklusif tidak boleh tumpang tindih.',
            );
        }
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
