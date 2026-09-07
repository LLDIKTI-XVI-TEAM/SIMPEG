<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PimpinanLeaveDecisionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_pimpinan_cannot_decide_a_leave_assigned_to_another_approver(): void
    {
        $leave = $this->leaveWithActiveStep(Employee::factory()->create());

        $this->actingAs(User::factory()->pimpinan()->create(['employee_id' => Employee::factory()->create()->id]))
            ->post(route('pimpinan.cuti.decision', $leave), [
                'active_step_id' => $leave->steps()->where('status', 'active')->valueOrFail('id'),
                'revision_version' => $leave->fresh()->revision_version,
                'keputusan' => 'DISETUJUI',
            ])
            ->assertForbidden();
    }

    public function test_non_approval_decisions_require_a_note(): void
    {
        $approver = Employee::factory()->create();
        $leave = $this->leaveWithActiveStep($approver);
        $user = User::factory()->pimpinan()->create(['employee_id' => $approver->id]);

        foreach (['DITANGGUHKAN', 'TIDAK_DISETUJUI'] as $decision) {
            $this->actingAs($user)
                ->from(route('pimpinan.cuti.show', $leave))
                ->post(route('pimpinan.cuti.decision', $leave), [
                    'active_step_id' => $leave->steps()->where('status', 'active')->valueOrFail('id'),
                    'revision_version' => $leave->fresh()->revision_version,
                    'keputusan' => $decision,
                ])
                ->assertRedirect(route('pimpinan.cuti.show', $leave))
                ->assertSessionHasErrors('catatan');
        }
    }

    public function test_keputusan_perubahan_tidak_lagi_diterima(): void
    {
        $approver = Employee::factory()->create();
        $leave = $this->leaveWithActiveStep($approver);
        $user = User::factory()->pimpinan()->create(['employee_id' => $approver->id]);

        $this->actingAs($user)
            ->from(route('pimpinan.cuti.show', $leave))
            ->post(route('pimpinan.cuti.decision', $leave), [
                'active_step_id' => $leave->steps()->where('status', 'active')->valueOrFail('id'),
                'revision_version' => $leave->fresh()->revision_version,
                'keputusan' => 'PERUBAHAN',
                'catatan' => 'Flow ini sudah tidak aktif.',
            ])
            ->assertRedirect(route('pimpinan.cuti.show', $leave))
            ->assertSessionHasErrors('keputusan');
    }

    public function test_terminal_leave_cannot_receive_another_decision(): void
    {
        $approver = Employee::factory()->create();
        $leave = $this->leaveWithActiveStep($approver, 'disetujui');

        $this->actingAs(User::factory()->pimpinan()->create(['employee_id' => $approver->id]))
            ->from(route('pimpinan.cuti.show', $leave))
            ->post(route('pimpinan.cuti.decision', $leave), [
                'active_step_id' => $leave->steps()->where('status', 'active')->valueOrFail('id'),
                'revision_version' => $leave->fresh()->revision_version,
                'keputusan' => 'DISETUJUI',
            ])
            ->assertRedirect(route('pimpinan.cuti.show', $leave))
            ->assertSessionHasErrors('status');
    }

    private function leaveWithActiveStep(Employee $approver, string $status = 'menunggu_approval'): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'employee_id' => Employee::factory()->create()->id,
            'jenis_cuti_id' => RefJenisCuti::create([
                'nama' => 'Cuti Sakit '.fake()->unique()->word(),
                'code' => 'cuti-sakit-pimpinan',
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ])->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => $status,
        ]);

        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        return $leave;
    }
}
