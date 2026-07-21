<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengunci timeline detail cuti agar dirender dari snapshot leave_request_steps (aktif/dilewati/waktu tindakan),
 * bukan dari presentasi tahap tetap. Termasuk rantai non-3-langkah dan langkah yang dilewati.
 */
class CutiDetailTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_detail_timeline_renders_dynamic_steps_including_skipped_and_acted(): void
    {
        // super_admin has cuti.read_all so it can view any request's detail.
        $viewer = User::factory()->superAdmin()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan', 'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false,
        ]);
        $employee = Employee::factory()->create();
        $kabag = Employee::factory()->create(['nama_lengkap' => 'Citra Kabag']);
        $verifikator = Employee::factory()->create(['nama_lengkap' => 'Doni Verifikator']);
        $pybmc = Employee::factory()->create(['nama_lengkap' => 'Eka Pimpinan']);

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Uji timeline dinamis',
            'status' => 'menunggu_approval',
        ]);

        // A FOUR-step snapshot (not three): approved, skipped, active, pending.
        $leaveRequest->steps()->create([
            'step_order' => 1, 'step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $kabag->id, 'status' => 'approved', 'is_final' => false,
            'acted_at' => now()->subDays(2),
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 2, 'step_type' => 'verifier', 'role_label' => 'Verifikator',
            'approver_employee_id' => $verifikator->id, 'status' => 'skipped', 'is_final' => false,
            'skipped_reason' => 'duplicate_approver',
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 3, 'step_type' => 'verifier', 'role_label' => 'Verifikator Kedua',
            'approver_employee_id' => $verifikator->id, 'status' => 'active', 'is_final' => false,
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 4, 'step_type' => 'pybmc', 'role_label' => 'PYBMC',
            'approver_employee_id' => $pybmc->id, 'status' => 'pending', 'is_final' => true,
        ]);

        $this->actingAs($viewer);
        $response = $this->get(route('cuti.show', $leaveRequest->id));

        $response->assertOk();
        // Approved step shows approver-based title.
        $response->assertSee('Disetujui oleh Kepala Bagian', false);
        // Skipped step is surfaced (not hidden as a fixed stage).
        $response->assertSee('Dilewati: Verifikator', false);
        // Active step shows waiting on the dynamic role label.
        $response->assertSee('Menunggu Verifikator Kedua', false);
        // Final pending step's role label appears.
        $response->assertSee('PYBMC', false);
        // No fixed-stage numbering leaked into the timeline.
        $response->assertDontSee('Stage 1', false);
        $response->assertDontSee('Stage 2', false);
        $response->assertDontSee('Stage 3', false);
    }

    public function test_active_approver_detail_uses_accessible_decision_dialogs(): void
    {
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $employee = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $approverUser = User::factory()->kepalaBagian()->create(['employee_id' => $approver->id]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Uji dialog keputusan',
            'status' => 'menunggu_approval',
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        $this->actingAs($approverUser)
            ->get(route('cuti.show', $leaveRequest->id))
            ->assertOk()
            ->assertSee('role="dialog"', false)
            ->assertSee('@keydown.escape.window="close()"', false)
            ->assertSee("@click=\"open('postpone', \$event)\"", false)
            ->assertSee("@click=\"open('decline', \$event)\"", false)
            ->assertDontSee("@click=\"open('reject', \$event)\"", false);
    }

    public function test_active_approver_actions_wrap_on_small_screens(): void
    {
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $employee = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $approverUser = User::factory()->kepalaBagian()->create(['employee_id' => $approver->id]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Uji tindakan responsif',
            'status' => 'menunggu_approval',
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        $this->actingAs($approverUser)
            ->get(route('cuti.show', $leaveRequest->id))
            ->assertOk()
            ->assertSee('flex-wrap justify-end gap-3', false);
    }

    public function test_detail_timeline_menampilkan_status_baru_dan_legacy_sebagai_tidak_disetujui(): void
    {
        $viewer = User::factory()->superAdmin()->create();
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'cuti-sakit-timeline-decline',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => Employee::factory()->create()->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-10',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji label keputusan tidak disetujui.',
            'status' => 'tidak_disetujui',
        ]);
        $approver = Employee::factory()->create();

        foreach (['tidak_disetujui', 'rejected'] as $index => $status) {
            $leaveRequest->steps()->create([
                'step_order' => $index + 1,
                'step_type' => 'verifikator',
                'role_label' => $index === 0 ? 'Verifikator Baru' : 'Verifikator Legacy',
                'approver_employee_id' => $approver->id,
                'status' => $status,
                'is_final' => $index === 1,
                'decision_note' => 'Dokumen pendukung tidak sesuai.',
                'acted_at' => now(),
            ]);
        }

        $response = $this->actingAs($viewer)->get(route('cuti.show', $leaveRequest->id));

        $response
            ->assertOk()
            ->assertSee('Tidak Disetujui oleh Verifikator Baru')
            ->assertSee('Tidak Disetujui oleh Verifikator Legacy')
            ->assertDontSee('Ditolak');
    }
}
