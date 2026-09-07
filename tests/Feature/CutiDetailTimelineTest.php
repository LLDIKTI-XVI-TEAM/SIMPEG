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
        $response->assertSee('Disetujui oleh Atasan Langsung', false);
        $response->assertDontSee('Disetujui oleh Kepala Bagian', false);
        // Skipped step is surfaced (not hidden as a fixed stage).
        $response->assertSee('Dilewati: Verifikator', false);
        // Active step shows waiting on the dynamic role label.
        $response->assertSee('Menunggu Verifikator Kedua', false);
        // Final pending step tetap menjelaskan pihak yang belum bertindak.
        $response->assertSee('Menunggu PYBMC', false);
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
        $activeStep = $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        $this->actingAs($approverUser)
            ->from(route('cuti.show', $leaveRequest->id))
            ->followingRedirects()
            ->post(route('cuti.approve', $leaveRequest->id), [
                'active_step_id' => '00000000-0000-4000-8000-000000000034',
                'revision_version' => $leaveRequest->fresh()->revision_version,
            ])
            ->assertOk()
            ->assertSee('Tahap persetujuan telah berubah. Muat ulang halaman sebelum mengirim keputusan.')
            ->assertSee('role="dialog"', false)
            ->assertSee('@keydown.escape.window="if (decisionForm !== null) close()"', false)
            ->assertDontSee('@keydown.escape.window="close()"', false)
            ->assertSee("@click=\"open('postpone', \$event)\"", false)
            ->assertSee("@click=\"open('decline', \$event)\"", false)
            ->assertSee('name="active_step_id"', false)
            ->assertSee('value="'.$activeStep->id.'"', false)
            ->assertDontSee("@click=\"open('reject', \$event)\"", false);
    }

    /** Akun tanpa mapping pegawai tidak boleh cocok dengan snapshot approver kosong. */
    public function test_user_without_employee_mapping_cannot_view_or_act_as_null_approver(): void
    {
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Snapshot Approver Kosong',
            'code' => 'snapshot-approver-kosong',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $applicant = Employee::factory()->create();
        $unmappedUser = User::factory()->kepalaBagian()->create(['employee_id' => null]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $applicant->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Uji otorisasi fail-closed untuk approver kosong.',
            'status' => 'menunggu_approval',
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => null,
            'status' => 'active',
            'is_final' => true,
        ]);

        $this->actingAsUnmapped($unmappedUser)
            ->get(route('cuti.show', $leaveRequest->id))
            ->assertRedirect(route('status-akun'));
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
            ->assertSee('flex flex-wrap items-end justify-end gap-3', false);
    }

    public function test_detail_timeline_menampilkan_status_tidak_disetujui(): void
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

        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'verifikator',
            'role_label' => 'Verifikator Baru',
            'approver_employee_id' => $approver->id,
            'status' => 'tidak_disetujui',
            'is_final' => true,
            'decision_note' => 'Dokumen pendukung tidak sesuai.',
            'acted_at' => now(),
        ]);

        $response = $this->actingAs($viewer)->get(route('cuti.show', $leaveRequest->id));

        $response
            ->assertOk()
            ->assertSee('Tidak Disetujui oleh Verifikator Baru')
            ->assertDontSee('Verifikator Legacy')
            ->assertDontSee('Ditolak');
    }

    public function test_employee_detail_explains_rollover_return_and_offers_target_year_resubmission(): void
    {
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Rollover Detail',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-12-28',
            'tanggal_selesai' => '2026-12-30',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Pengajuan yang harus dipindahkan.',
            'alamat_selama_cuti' => 'Jl. Tahun Sumber',
            'nomor_telepon' => '+62 431 123456',
            'status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
            'rollover_source_year' => 2026,
            'rollover_target_year' => 2027,
        ]);

        $this->actingAs($user)
            ->get(route('cuti.show', $leaveRequest->id))
            ->assertOk()
            ->assertSee('Pengajuan Dikembalikan karena Rollover')
            ->assertSee('Tahun Sumber')
            ->assertSee('Tahun Target')
            ->assertSee('Perbaiki dan Ajukan Kembali')
            ->assertSee('min="2027-01-01"', false)
            ->assertSee('aria-labelledby="rollover-return-title"', false)
            ->assertDontSee('role="status"', false)
            ->assertSee('aria-describedby="rollover-target-year-hint"', false)
            ->assertSee('Saldo Target Dapat Diajukan')
            ->assertDontSee('value="2026-12-28"', false);
    }

    /**
     * Rollover mempertahankan step approval aktif agar snapshot tidak hilang. Approver snapshot
     * tetap boleh membuka detail sebagai riwayat, tetapi tidak boleh ditawari tombol keputusan
     * karena pengajuan hanya dapat diperbaiki oleh pemohon pada tahun target.
     */
    public function test_snapshot_approver_can_view_rollover_return_detail_without_decision_actions(): void
    {
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Rollover Approver',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $applicant = Employee::factory()->create();
        $approver = Employee::factory()->create();
        // Kepala Bagian tidak memiliki cuti.read_all, sehingga aksesnya bergantung pada snapshot approver.
        $approverUser = User::factory()->kepalaBagian()->create(['employee_id' => $approver->id]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $applicant->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-12-28',
            'tanggal_selesai' => '2026-12-30',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Pengajuan dikembalikan saat rollover.',
            'status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
            'rollover_source_year' => 2026,
            'rollover_target_year' => 2027,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => false,
        ]);

        $this->actingAs($approverUser)
            ->get(route('cuti.show', $leaveRequest->id))
            ->assertOk()
            ->assertSee('Dikembalikan karena Rollover')
            ->assertDontSee(route('cuti.approve', $leaveRequest->id), false)
            ->assertDontSee(route('cuti.postpone', $leaveRequest->id), false)
            ->assertDontSee('Minta Perubahan')
            ->assertDontSee(route('cuti.decline', $leaveRequest->id), false);
    }

    /**
     * Setiap approver pada snapshot tetap dapat membaca riwayat pengajuan,
     * tetapi hanya approver tahap aktif yang boleh menerima kontrol keputusan.
     */
    public function test_all_snapshot_approvers_can_view_detail_but_only_active_approver_can_act(): void
    {
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Snapshot Akses Detail',
            'code' => 'snapshot-akses-detail',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $applicant = Employee::factory()->create();
        $completedApprover = Employee::factory()->create();
        $activeApprover = Employee::factory()->create();
        $futureApprover = Employee::factory()->create();
        $unrelatedEmployee = Employee::factory()->create();
        $completedUser = User::factory()->kepalaBagian()->create(['employee_id' => $completedApprover->id]);
        $activeUser = User::factory()->kepalaBagian()->create(['employee_id' => $activeApprover->id]);
        $futureUser = User::factory()->kepalaBagian()->create(['employee_id' => $futureApprover->id]);
        $unrelatedUser = User::factory()->kepalaBagian()->create(['employee_id' => $unrelatedEmployee->id]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $applicant->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Uji pembacaan snapshot semua approver.',
            'status' => 'menunggu_approval',
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $completedApprover->id,
            'status' => 'approved',
            'is_final' => false,
            'acted_at' => now()->subDay(),
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 2,
            'step_type' => 'verifikator',
            'role_label' => 'Verifikator Aktif',
            'approver_employee_id' => $activeApprover->id,
            'status' => 'active',
            'is_final' => false,
        ]);
        $leaveRequest->steps()->create([
            'step_order' => 3,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $futureApprover->id,
            'status' => 'pending',
            'is_final' => true,
        ]);

        $this->actingAs($completedUser)
            ->get(route('cuti.show', $leaveRequest->id))
            ->assertOk()
            ->assertDontSee(route('cuti.approve', $leaveRequest->id), false);

        $this->actingAs($futureUser)
            ->get(route('cuti.show', $leaveRequest->id))
            ->assertOk()
            ->assertDontSee(route('cuti.approve', $leaveRequest->id), false);

        $this->actingAs($activeUser)
            ->get(route('cuti.show', $leaveRequest->id))
            ->assertOk()
            ->assertSee(route('cuti.approve', $leaveRequest->id), false);

        $this->actingAs($unrelatedUser)
            ->get(route('cuti.show', $leaveRequest->id))
            ->assertForbidden();
    }

    public function test_employee_detail_marks_target_balance_unavailable_when_preview_is_null(): void
    {
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Rollover Tanpa Target',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $employee = Employee::factory()->create();
        $user = User::factory()->pegawai()->create(['employee_id' => $employee->id]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-12-28',
            'tanggal_selesai' => '2026-12-30',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Metadata target belum tersedia.',
            'status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
            'rollover_source_year' => null,
            'rollover_target_year' => null,
        ]);

        $this->actingAs($user)
            ->get(route('cuti.show', $leaveRequest->id))
            ->assertOk()
            ->assertSee('Saldo target belum tersedia')
            ->assertDontSee('Saldo Target Dapat Diajukan</dt><dd class="mt-1 font-semibold text-ink">0 hari', false);
    }

    public function test_file_domain_cuti_tidak_memuat_token_keputusan_legacy(): void
    {
        // Literal berkutip mencegah false positive dari method, identifier, dan prosa.
        $files = [
            'app/Services/Cuti/LeaveProofService.php',
            'resources/views/admin/cuti/show.blade.php',
            'resources/views/pimpinan/cuti/show.blade.php',
            'resources/views/kabag/cuti/show.blade.php',
        ];
        $forbiddenTokens = [
            "'rejected'" => 'status legacy single-quoted',
            '"rejected"' => 'status legacy double-quoted',
            "'REJECT'" => 'action legacy single-quoted',
            '"REJECT"' => 'action legacy double-quoted',
        ];

        foreach ($files as $relativePath) {
            $contents = file_get_contents(base_path($relativePath));
            $this->assertNotFalse($contents, "Gagal membaca file domain cuti: {$relativePath}");

            foreach ($forbiddenTokens as $token => $description) {
                $this->assertStringNotContainsString(
                    $token,
                    $contents,
                    "File {$relativePath} masih memuat {$description}: {$token}.",
                );
            }
        }
    }
}
