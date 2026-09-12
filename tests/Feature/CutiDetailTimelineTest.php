<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\Role;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
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

    public function test_monitoring_detail_dan_lampiran_tidak_melewati_scope_pegawai(): void
    {
        $leave = $this->administrativePostponementFixture();
        $actor = User::factory()->pegawai()->create();
        Role::where('name', 'pegawai')->firstOrFail()->permissions()->syncWithoutDetaching([
            Permission::where('name', 'cuti.read_all')->valueOrFail('id'),
        ]);

        $this->actingAs($actor)->get(route('cuti.show', $leave))->assertForbidden();
        $this->get(route('cuti.attachment.download', $leave))->assertForbidden();

        $owner = User::factory()->pegawai()->create(['employee_id' => $leave->employee_id]);
        $this->actingAs($owner)->get(route('cuti.show', $leave))->assertOk();
    }

    public function test_monitoring_detail_membatasi_bawahan_efektif_dan_mempertahankan_scope_identitas_asli(): void
    {
        $leave = $this->administrativePostponementFixture();
        $supervisor = Employee::factory()->create();
        $actor = User::factory()->kepalaBagian()->create(['employee_id' => $supervisor->id]);
        Role::where('name', 'kepala_bagian')->firstOrFail()->permissions()->syncWithoutDetaching([
            Permission::where('name', 'cuti.read_all')->valueOrFail('id'),
        ]);
        $assignment = SupervisorAssignment::create([
            'employee_id' => $leave->employee_id,
            'kepala_bagian_id' => $supervisor->id,
            'supervisor_id' => $supervisor->id,
            'tanggal_mulai' => today()->addDay(),
        ]);

        $this->actingAs($actor)->get(route('cuti.show', $leave))->assertForbidden();
        $assignment->update(['tanggal_mulai' => today()]);
        $this->get(route('cuti.show', $leave))->assertOk();

        $simulated = User::factory()->superAdmin()->create(['temporary_role' => 'kepala_bagian']);
        $this->actingAs($simulated)->get(route('cuti.show', $leave))->assertOk();
    }

    public function test_permission_monitoring_tidak_otomatis_memuat_saldo_lintas_pegawai(): void
    {
        $leave = $this->administrativePostponementFixture();
        $actor = User::factory()->pimpinan()->create();
        $role = Role::where('name', 'pimpinan')->firstOrFail();
        $balancePermission = Permission::where('name', 'cuti.balance.read')->valueOrFail('id');
        $role->permissions()->detach($balancePermission);

        $this->actingAs($actor)->get(route('cuti.show', $leave))
            ->assertOk()
            ->assertViewHas('verifierContext', fn ($context) => $context === null)
            ->assertViewHas('targetBalance', fn ($balance) => $balance === null);

        $role->permissions()->attach($balancePermission);
        $this->get(route('cuti.show', $leave))
            ->assertOk()->assertViewHas('verifierContext', fn ($context) => is_array($context));
    }

    public function test_pembaca_snapshot_dengan_izin_saldo_dan_scope_sah_tidak_memerlukan_izin_monitoring(): void
    {
        $leave = $this->administrativePostponementFixture();
        $employee = Employee::factory()->create();
        $actor = User::factory()->pimpinan()->create(['employee_id' => $employee->id]);
        Role::where('name', 'pimpinan')->firstOrFail()->permissions()->detach(
            Permission::where('name', 'cuti.read_all')->valueOrFail('id'),
        );
        Role::where('name', 'pimpinan')->firstOrFail()->permissions()->syncWithoutDetaching([
            Permission::where('name', 'cuti.balance.read')->valueOrFail('id'),
        ]);
        $leave->steps()->create([
            'step_order' => 1, 'step_type' => 'pybmc', 'role_label' => 'PYBMC',
            'approver_employee_id' => $employee->id, 'status' => 'approved', 'is_final' => true,
        ]);

        $this->actingAs($actor)->get(route('cuti.show', $leave))
            ->assertOk()->assertViewHas('canAct', false)
            ->assertViewHas('verifierContext', fn ($context) => is_array($context));

        // Snapshot tetap memberi hak baca record, tetapi grant saldo tidak memperluas scope Pegawai.
        Role::where('name', 'pegawai')->firstOrFail()->permissions()->syncWithoutDetaching([
            Permission::where('name', 'cuti.balance.read')->valueOrFail('id'),
        ]);
        $actor->forceFill(['role' => 'pegawai'])->save();
        $this->actingAs($actor)->get(route('cuti.show', $leave))
            ->assertOk()->assertViewHas('verifierContext', fn ($context) => $context === null);
    }

    public function test_detail_role_adalah_redirect_berotorisasi_ke_detail_kanonis(): void
    {
        $leave = $this->administrativePostponementFixture();
        $actor = User::factory()->pimpinan()->create();

        $this->actingAs($actor)->get(route('pimpinan.cuti.show', $leave))
            ->assertRedirect(route('cuti.show', ['id' => $leave->id, 'from' => 'pimpinan']));

        Role::where('name', 'pimpinan')->firstOrFail()->permissions()->detach(
            Permission::where('name', 'cuti.read_all')->valueOrFail('id'),
        );
        $this->get(route('pimpinan.cuti.show', $leave))->assertForbidden();
        $this->get(route('cuti.show', $leave))->assertForbidden();
    }

    public function test_adapter_detail_mempertahankan_flash_validasi_dari_form_sebelumnya(): void
    {
        $leave = $this->administrativePostponementFixture();
        $actor = User::factory()->pegawai()->create(['employee_id' => $leave->employee_id]);
        session()->flash('errors', (new ViewErrorBag)->put('default', new MessageBag([
            'status' => ['Pengajuan sedang menunggu keputusan pembatalan.'],
        ])));
        session()->flash('_old_input', ['catatan' => 'Draft belum tersimpan']);

        $this->actingAs($actor)->followingRedirects()->get(route('pimpinan.cuti.show', $leave))
            ->assertOk()->assertSee('Pengajuan sedang menunggu keputusan pembatalan.');
    }

    public function test_konteks_kembali_hanya_memilih_route_lokal_tanpa_memperluas_akses(): void
    {
        $leave = $this->administrativePostponementFixture();
        $actor = User::factory()->pegawai()->create(['employee_id' => $leave->employee_id]);
        foreach (['https://example.test', 'monitoring', ['pimpinan']] as $from) {
            $this->actingAs($actor)->get(route('cuti.show', ['id' => $leave->id, 'from' => $from]))
                ->assertOk()->assertViewHas('backLink', [
                    'url' => route('cuti', ['scope' => 'own']),
                    'label' => 'Kembali ke Pengajuan Cuti Saya',
                ]);
        }
        $this->get(route('cuti.show', ['id' => $leave->id, 'from' => 'approval']))
            ->assertOk()->assertViewHas('backLink', [
                'url' => route('cuti.approval'), 'label' => 'Kembali ke Menunggu Tindakan Saya',
            ]);
    }

    public function test_detail_final_menawarkan_penangguhan_administratif_hanya_kepada_pengelola_berizin(): void
    {
        $leave = $this->administrativePostponementFixture();
        $admin = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($admin)->get(route('cuti.show', $leave))
            ->assertOk()
            ->assertSee('Tangguhkan secara Administratif')
            ->assertSee('name="alasan"', false)
            ->assertSee('maxlength="500"', false)
            ->assertSee('Seluruh periode cuti akan dibatalkan');

        $this->assertSame(1, preg_match('/<dl id="administrative-target-summary"[^>]*>(.*?)<\/dl>/s', $response->getContent(), $summary));
        $this->assertStringContainsString(e($leave->employee->nama_lengkap), $summary[1]);
        $this->assertStringContainsString($leave->tanggal_mulai->translatedFormat('d F Y'), $summary[1]);
        $this->assertStringContainsString('2 hari kerja', $summary[1]);

        $reader = User::factory()->superAdmin()->create();
        $this->actingAs($reader)->get(route('cuti.show', $leave))
            ->assertOk()->assertDontSee('Tangguhkan secara Administratif');

        $leave->forceFill(['tanggal_mulai' => now()->toDateString()])->save();
        $this->actingAs($admin)->get(route('cuti.show', $leave))
            ->assertOk()->assertDontSee('Tangguhkan secara Administratif');
    }

    public function test_detail_administratif_memisahkan_alasan_privat_dari_monitoring_dan_error_form_lama(): void
    {
        $leave = $this->administrativePostponementFixture();
        $admin = User::factory()->adminKepegawaian()->create();
        $reason = 'Instruksi penangguhan administratif yang bersifat privat.';
        $leave->forceFill([
            'status' => 'ditangguhkan_administratif',
            'administratively_postponed_at' => now(),
            'administratively_postponed_by' => $admin->id,
            'administrative_postponement_reason' => $reason,
        ])->save();

        $owner = User::factory()->pegawai()->create(['employee_id' => $leave->employee_id]);
        foreach ([$owner, $admin] as $viewer) {
            $this->actingAs($viewer)->get(route('cuti.show', $leave))
                ->assertOk()->assertSee('Ditangguhkan (Administratif)')->assertSee($reason)
                ->assertDontSee('Tangguhkan secara Administratif');
        }

        $reader = User::factory()->superAdmin()->create();
        $this->actingAs($reader)->get(route('cuti.show', $leave))
            ->assertOk()->assertSee('Ditangguhkan (Administratif)')->assertDontSee($reason);

        $errors = new ViewErrorBag;
        $errors->put('administrativePostponement', new MessageBag([
            'status' => 'Status pengajuan sudah berubah. Muat ulang halaman.',
        ]));
        $this->actingAs($admin)->withSession(['errors' => $errors])->get(route('cuti.show', $leave))
            ->assertOk()->assertSee('Status pengajuan sudah berubah. Muat ulang halaman.')
            ->assertDontSee('Periksa kembali data penangguhan di formulir');
    }

    private function administrativePostponementFixture(): LeaveRequest
    {
        $type = RefJenisCuti::create([
            'nama' => 'Cuti Sakit', 'code' => 'sakit',
            'mengurangi_saldo_tahunan' => false, 'khusus_pns' => false,
        ]);

        return LeaveRequest::create([
            'employee_id' => Employee::factory()->create()->id,
            'jenis_cuti_id' => $type->id,
            'tanggal_mulai' => now()->addWeek()->toDateString(),
            'tanggal_selesai' => now()->addWeek()->addDay()->toDateString(),
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengajuan uji presentasi keputusan administratif.',
            'status' => 'disetujui',
        ]);
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
