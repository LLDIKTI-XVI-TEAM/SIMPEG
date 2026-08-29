<?php

namespace Tests\Feature;

use App\Actions\Employees\ChangeEmployeeStatusAction;
use App\Models\Employee;
use App\Models\EmployeeStatusTransition;
use App\Models\RefStatusPegawai;
use App\Models\User;
use App\Services\Employees\EmployeeStatusLifecycleService;
use App\Services\Employees\EmployeeStatusTransitionService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmployeeStatusTargetAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);
    }

    public function test_target_nonaktif_ditolak_pada_jalur_schedule_tanpa_side_effect(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $target = $this->customStatus('TARGET_INACTIVE_SCHEDULE');
        $target->forceFill(['is_active' => false])->saveOrFail();
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();

        Auth::login($actor);

        try {
            app(ChangeEmployeeStatusAction::class)->execute($employee, [
                'status_pegawai_id' => $target->id,
                'tanggal' => $tanggal,
                'keterangan' => 'Target nonaktif wajib ditolak.',
            ], $this->requestFor($actor));
            $this->fail('Target status nonaktif tidak boleh dijadwalkan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status_pegawai_id', $exception->errors());
        } finally {
            Auth::logout();
        }

        $this->assertDatabaseCount('employee_status_transitions', 0);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_target_yang_sudah_dihapus_ditolak_sebagai_validasi_pada_mutasi_langsung(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $target = $this->customStatus('TARGET_DELETED_IMMEDIATE');
        $target->delete();

        try {
            app(EmployeeStatusLifecycleService::class)->mutate(
                $employee,
                $target,
                now('Asia/Makassar')->toDateString(),
                'Target sudah dihapus.',
                $this->requestFor($actor),
            );
            $this->fail('Target status yang sudah dihapus wajib ditolak sebagai validasi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status_pegawai_id', $exception->errors());
        }

        $this->assertTrue($employee->refresh()->isActive());
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_action_perubahan_status_menerjemahkan_target_hilang_menjadi_validasi(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        try {
            app(ChangeEmployeeStatusAction::class)->execute($employee, [
                'status_pegawai_id' => '11111111-1111-4111-8111-111111111111',
                'tanggal' => now('Asia/Makassar')->toDateString(),
                'keterangan' => 'Target sudah hilang setelah validasi awal.',
            ], $this->requestFor($actor));
            $this->fail('Action wajib mengembalikan kontrak validasi untuk target yang hilang.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status_pegawai_id', $exception->errors());
        }

        $this->assertTrue($employee->refresh()->isActive());
        $this->assertDatabaseCount('employee_status_transitions', 0);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_route_perubahan_status_menolak_target_hilang_tanpa_mutasi(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($actor)
            ->post(route('pegawai.status.update'), [
                'pegawai_id' => $employee->id,
                'status_pegawai_id' => '11111111-1111-4111-8111-111111111111',
                'tanggal' => now('Asia/Makassar')->toDateString(),
                'keterangan' => 'Target route tidak tersedia.',
            ])
            ->assertSessionHasErrors('status_pegawai_id');

        $this->assertTrue($employee->refresh()->isActive());
        $this->assertDatabaseCount('employee_status_transitions', 0);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_apply_due_membiarkan_jadwal_pending_ketika_target_dinonaktifkan(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create();
        $target = $this->customStatus('TARGET_INACTIVE_DUE');
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();

        Auth::login($actor);
        app(ChangeEmployeeStatusAction::class)->execute($employee, [
            'status_pegawai_id' => $target->id,
            'tanggal' => $tanggal,
            'keterangan' => 'Target akan dinonaktifkan sebelum due.',
        ], $this->requestFor($actor));
        Auth::logout();

        $target->forceFill(['is_active' => false])->saveOrFail();

        $this->assertSame(0, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
        $this->assertFalse(EmployeeStatusTransition::query()->sole()->is_applied);
        $this->assertTrue($employee->refresh()->isActive());
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function customStatus(string $code): RefStatusPegawai
    {
        return RefStatusPegawai::create([
            'kode' => $code,
            'nama' => str_replace('_', ' ', $code),
            'kelompok' => 'Aktif/khusus',
            'is_active' => true,
        ]);
    }

    private function requestFor(User $actor): Request
    {
        $request = Request::create('/uji-target-status', 'POST', [], [], [], [
            'REMOTE_ADDR' => '10.27.0.8',
            'HTTP_USER_AGENT' => 'SIMPEG-Target-Availability/1.0',
        ]);
        $request->setUserResolver(static fn (): User => $actor);

        return $request;
    }
}
