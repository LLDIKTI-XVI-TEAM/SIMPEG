<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KepalaBagianFrontendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_dashboard_redirects_kepala_bagian_and_only_shows_direct_reports(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan Langsung',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $otherEmployee = Employee::factory()->create(['nama_lengkap' => 'Bukan Bawahan']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('kepala-bagian.dashboard'));

        $this->actingAs($user)
            ->get(route('kepala-bagian.dashboard'))
            ->assertOk()
            ->assertSee('Bawahan Langsung')
            ->assertDontSee('Bukan Bawahan')
            ->assertSee(route('kepala-bagian.cuti.index'), false);

        $this->assertNotNull($directReport->id);
        $this->assertNotNull($otherEmployee->id);
    }

    public function test_navigation_menampilkan_cuti_bawahan_dan_pengajuan_cuti_sendiri(): void
    {
        [$user] = $this->kepalaBagian();

        $this->actingAs($user)
            ->get(route('kepala-bagian.dashboard'))
            ->assertOk()
            ->assertSee('Cuti Bawahan')
            ->assertSee('href="'.route('kepala-bagian.cuti.index').'"', false)
            ->assertSee('Pengajuan Cuti')
            ->assertSee('href="'.route('cuti').'"', false);
    }

    public function test_employee_pages_are_limited_to_direct_reports(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Bawahan',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $otherEmployee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Unit Lain']);

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.index'))
            ->assertOk()
            ->assertSee('Pegawai Bawahan')
            ->assertDontSee('Pegawai Unit Lain');

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.show', $directReport))
            ->assertOk()
            ->assertSee('Pegawai Bawahan');

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.show', $otherEmployee))
            ->assertForbidden();
    }

    public function test_leave_queue_and_detail_use_real_scoped_data_and_contract(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Bawahan',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $otherEmployee = Employee::factory()->create(['nama_lengkap' => 'Pemohon Lain']);
        $visibleLeave = $this->leaveWithActiveStep($directReport, $kepalaBagian);
        $hiddenLeave = $this->leaveWithActiveStep($otherEmployee, $kepalaBagian);

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.index'))
            ->assertOk()
            ->assertSee('Pemohon Bawahan')
            ->assertDontSee('Pemohon Lain')
            ->assertSee(route('kepala-bagian.cuti.show', $visibleLeave), false);

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.show', $visibleLeave))
            ->assertOk()
            ->assertSee(route('kepala-bagian.cuti.decision', $visibleLeave), false)
            ->assertSee('Konfirmasi Persetujuan')
            ->assertSee('required', false)
            ->assertDontSee('Simulasi');

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.show', $hiddenLeave))
            ->assertForbidden();
    }

    public function test_kepala_bagian_decision_uses_leave_workflow_and_requires_note_when_needed(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $leave = $this->leaveWithActiveStep($directReport, $kepalaBagian);

        $this->actingAs($user)
            ->from(route('kepala-bagian.cuti.show', $leave))
            ->post(route('kepala-bagian.cuti.decision', $leave), ['keputusan' => 'PERUBAHAN'])
            ->assertRedirect(route('kepala-bagian.cuti.show', $leave))
            ->assertSessionHasErrors('catatan');

        $this->actingAs($user)
            ->post(route('kepala-bagian.cuti.decision', $leave), [
                'keputusan' => 'DISETUJUI',
                'catatan' => 'Diteruskan ke tahapan berikutnya.',
            ])
            ->assertRedirect(route('kepala-bagian.cuti.show', $leave))
            ->assertSessionHas('success', 'Pengajuan cuti berhasil disetujui.');

        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leave->id,
            'approver_id' => $kepalaBagian->id,
            'action' => 'APPROVE',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'APPROVE',
            'auditable_type' => 'LeaveRequest',
            'auditable_id' => $leave->id,
        ]);
    }

    public function test_detail_cuti_bawahan_menampilkan_status_tidak_disetujui_baru_dan_legacy(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $leave = $this->leaveWithActiveStep($directReport, $kepalaBagian);
        $leave->forceFill(['status' => 'tidak_disetujui'])->save();
        $leave->steps()->delete();

        foreach (['tidak_disetujui', 'rejected'] as $index => $status) {
            LeaveRequestStep::create([
                'leave_request_id' => $leave->id,
                'step_order' => $index + 1,
                'step_type' => 'kepala_bagian',
                'role_label' => $index === 0 ? 'Kepala Bagian Baru' : 'Kepala Bagian Legacy',
                'approver_employee_id' => $kepalaBagian->id,
                'status' => $status,
                'is_final' => $index === 1,
                'acted_at' => now(),
            ]);
        }

        $response = $this->actingAs($user)->get(route('kepala-bagian.cuti.show', $leave));

        $response
            ->assertOk()
            ->assertSeeInOrder(['Kepala Bagian Baru', 'Tidak Disetujui'])
            ->assertSeeInOrder(['Kepala Bagian Legacy', 'Tidak Disetujui'])
            ->assertDontSee('Ditolak')
            ->assertDontSee('Rejected');
        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), 'border-danger'));
    }

    public function test_ews_page_only_exposes_alerts_for_direct_reports(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan EWS',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $otherEmployee = Employee::factory()->create(['nama_lengkap' => 'Pegawai EWS Lain']);

        EwsAlert::create([
            'employee_id' => $directReport->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        EwsAlert::create([
            'employee_id' => $otherEmployee->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('kepala-bagian.ews.index'))
            ->assertOk()
            ->assertSee('Bawahan EWS')
            ->assertDontSee('Pegawai EWS Lain')
            ->assertSee(route('kepala-bagian.bawahan.show', $directReport), false);
    }

    /** @return array{0: User, 1: Employee} */
    private function kepalaBagian(): array
    {
        $employee = Employee::factory()->create();

        return [
            User::factory()->kepalaBagian()->create(['employee_id' => $employee->id]),
            $employee,
        ];
    }

    private function leaveWithActiveStep(Employee $applicant, Employee $approver): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'employee_id' => $applicant->id,
            'jenis_cuti_id' => RefJenisCuti::create([
                'nama' => 'Cuti Sakit '.fake()->unique()->word(),
                'code' => 'cuti-sakit-kabag-'.fake()->unique()->numerify('############'),
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ])->id,
            'tanggal_mulai' => '2026-07-20',
            'tanggal_selesai' => '2026-07-22',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);

        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        return $leave;
    }
}
