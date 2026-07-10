<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmployeePerformanceFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_can_update_performance_flag(): void
    {
        $employee = Employee::factory()->create(['is_kinerja_baik' => true]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJsonWithCsrf(route('pegawai.kinerja.update', $employee->id), [
                'is_kinerja_baik' => false,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Status kinerja pegawai berhasil diperbarui.')
            ->assertJsonPath('is_kinerja_baik', false);

        $this->assertFalse($employee->refresh()->is_kinerja_baik);
    }

    public function test_admin_kepegawaian_can_update_performance_flag(): void
    {
        $employee = Employee::factory()->create(['is_kinerja_baik' => false]);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->postJsonWithCsrf(route('pegawai.kinerja.update', $employee->id), [
                'is_kinerja_baik' => true,
            ])
            ->assertOk()
            ->assertJsonPath('is_kinerja_baik', true);

        $this->assertTrue($employee->refresh()->is_kinerja_baik);
    }

    public function test_pimpinan_cannot_update_performance_flag(): void
    {
        $employee = Employee::factory()->create(['is_kinerja_baik' => true]);

        $this->actingAs(User::factory()->pimpinan()->create())
            ->postJsonWithCsrf(route('pegawai.kinerja.update', $employee->id), [
                'is_kinerja_baik' => false,
            ])
            ->assertForbidden();

        $this->assertTrue($employee->refresh()->is_kinerja_baik);
    }

    public function test_pegawai_cannot_update_performance_flag(): void
    {
        $employee = Employee::factory()->create(['is_kinerja_baik' => true]);

        $this->actingAs(User::factory()->pegawai()->create())
            ->postJsonWithCsrf(route('pegawai.kinerja.update', $employee->id), [
                'is_kinerja_baik' => false,
            ])
            ->assertForbidden();

        $this->assertTrue($employee->refresh()->is_kinerja_baik);
    }

    public function test_kepala_bagian_cannot_update_performance_flag(): void
    {
        $employee = Employee::factory()->create(['is_kinerja_baik' => true]);

        $this->actingAs(User::factory()->kepalaBagian()->create())
            ->postJsonWithCsrf(route('pegawai.kinerja.update', $employee->id), [
                'is_kinerja_baik' => false,
            ])
            ->assertForbidden();

        $this->assertTrue($employee->refresh()->is_kinerja_baik);
    }

    public function test_guest_is_redirected_from_performance_flag_update(): void
    {
        $employee = Employee::factory()->create(['is_kinerja_baik' => true]);

        $this->postJsonWithCsrf(route('pegawai.kinerja.update', $employee->id), [
            'is_kinerja_baik' => false,
        ])->assertRedirect(route('login'));

        $this->assertTrue($employee->refresh()->is_kinerja_baik);
    }

    public function test_invalid_payload_is_rejected(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->postJsonWithCsrf(route('pegawai.kinerja.update', $employee->id), [
                'is_kinerja_baik' => 'bukan_boolean',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_kinerja_baik']);
    }

    public function test_missing_payload_is_rejected(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->postJsonWithCsrf(route('pegawai.kinerja.update', $employee->id), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_kinerja_baik']);
    }

    public function test_malformed_employee_id_returns_not_found(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->postJsonWithCsrf(route('pegawai.kinerja.update', 'bukan-uuid'), [
                'is_kinerja_baik' => false,
            ])
            ->assertNotFound();
    }

    public function test_valid_missing_employee_id_returns_not_found(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->postJsonWithCsrf(route('pegawai.kinerja.update', Str::uuid()), [
                'is_kinerja_baik' => false,
            ])
            ->assertNotFound();
    }

    public function test_update_writes_audit_log_with_before_and_after_values(): void
    {
        $employee = Employee::factory()->create(['is_kinerja_baik' => true]);
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->postJsonWithCsrf(route('pegawai.kinerja.update', $employee->id), [
                'is_kinerja_baik' => false,
            ])
            ->assertOk();

        $log = AuditLog::query()
            ->where('event', 'UPDATE')
            ->where('auditable_type', 'Employee')
            ->where('auditable_id', $employee->id)
            ->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(true, $log->old_values['is_kinerja_baik']);
        $this->assertSame(false, $log->new_values['is_kinerja_baik']);
    }

    /**
     * Menyertakan token CSRF agar test JSON tetap melewati middleware web seperti interaksi Alpine di halaman detail.
     *
     * @param  array<string, mixed>  $data
     */
    private function postJsonWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, array_merge($data, ['_token' => 'test-token']));
    }
}
