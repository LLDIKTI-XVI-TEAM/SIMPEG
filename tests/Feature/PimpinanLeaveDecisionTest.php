<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PimpinanLeaveDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_duty_postponement_pimpinan_route_records_terminal_workflow(): void
    {
        $fixture = $this->dutyPostponementFixture();

        $this->actingAs($fixture['user'])
            ->post(route('pimpinan.cuti.penangguhan-tugas-dinas', $fixture['leave']), ['alasan' => 'Penugasan mendesak mewakili instansi.'])
            ->assertRedirect(route('pimpinan.cuti.show', $fixture['leave']))
            ->assertSessionHas('success', 'Cuti Tahunan ditangguhkan karena tugas dinas dan hak terkait telah dilindungi untuk satu tahun berikutnya.');

        $this->assertDatabaseHas('leave_requests', ['id' => $fixture['leave']->id, 'status' => LeaveRequest::STATUS_DUTY_POSTPONED]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $fixture['leave']->id,
            'approver_id' => $fixture['approver']->id,
            'action' => LeaveApproval::ACTION_DUTY_POSTPONEMENT,
        ]);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $fixture['leave']->id,
            'step_order' => 2,
            'status' => 'skipped',
            'skipped_reason' => LeaveRequestStep::SKIPPED_DUTY_POSTPONEMENT_TERMINAL,
        ]);
    }

    public function test_duty_postponement_pimpinan_route_validates_reason_without_mutation(): void
    {
        foreach ([
            '' => 'Alasan tugas dinas mendesak wajib diisi.',
            'abcd' => 'Alasan tugas dinas minimal berisi 5 karakter.',
            str_repeat('a', 501) => 'Alasan tugas dinas maksimal berisi 500 karakter.',
        ] as $reason => $message) {
            $fixture = $this->dutyPostponementFixture();

            $this->actingAs($fixture['user'])
                ->from(route('pimpinan.cuti.show', $fixture['leave']))
                ->post(route('pimpinan.cuti.penangguhan-tugas-dinas', $fixture['leave']), ['alasan' => $reason])
                ->assertRedirect(route('pimpinan.cuti.show', $fixture['leave']))
                ->assertSessionHasErrorsIn('dutyPostponement', ['alasan' => $message]);

            $this->assertSame('menunggu_approval', $fixture['leave']->fresh()->status);
            $this->assertDatabaseMissing('leave_approvals', ['leave_request_id' => $fixture['leave']->id]);
        }
    }

    public function test_duty_postponement_pimpinan_route_rejects_malformed_uuid(): void
    {
        $this->actingAs(User::factory()->pimpinan()->create())
            ->post('/pimpinan/cuti/bukan-uuid/penangguhan-tugas-dinas', ['alasan' => 'Penugasan mendesak mewakili instansi.'])
            ->assertNotFound();
    }

    public function test_duty_postponement_pimpinan_route_rejects_non_snapshot_actor_without_mutation(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $other = Employee::factory()->create();
        $user = User::factory()->pimpinan()->create(['employee_id' => $other->id]);

        $this->actingAs($user)
            ->post(route('pimpinan.cuti.penangguhan-tugas-dinas', $fixture['leave']), ['alasan' => 'Penugasan mendesak mewakili instansi.'])
            ->assertForbidden();

        $this->assertSame('menunggu_approval', $fixture['leave']->fresh()->status);
        $this->assertDatabaseMissing('leave_approvals', ['leave_request_id' => $fixture['leave']->id]);
    }

    public function test_duty_postponement_pimpinan_detail_shows_distinct_actions_only_for_eligible_actor(): void
    {
        $fixture = $this->dutyPostponementFixture();

        $this->actingAs($fixture['user'])
            ->get(route('pimpinan.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertSee('Tunda Sementara')
            ->assertSee('Tangguhkan karena Tugas Dinas')
            ->assertSee(route('pimpinan.cuti.penangguhan-tugas-dinas', $fixture['leave']), false)
            ->assertSee('name="alasan"', false)
            ->assertSee('minlength="5"', false)
            ->assertSee('maxlength="500"', false)
            ->assertSee('Konfirmasi Penangguhan Tugas Dinas')
            ->assertSee('openDutyPostponement($event)', false)
            ->assertSee('closeDutyPostponement()', false)
            ->assertSee("document.getElementById('pimpinan-duty-postponement-reason')?.focus()", false)
            ->assertSee('dutyPostponementTrigger?.focus()', false)
            ->assertSee('aria-describedby="pimpinan-duty-postponement-description"', false);

        $other = Employee::factory()->create();
        $otherUser = User::factory()->pimpinan()->create(['employee_id' => $other->id]);
        $this->actingAs($otherUser)
            ->get(route('pimpinan.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertDontSee(route('pimpinan.cuti.penangguhan-tugas-dinas', $fixture['leave']), false);
    }

    public function test_duty_postponement_pimpinan_detail_hides_action_for_non_annual_and_renders_terminal_history(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $annualTypeId = $fixture['leave']->jenis_cuti_id;
        $sickType = RefJenisCuti::create([
            'nama' => 'Cuti Sakit UI Pimpinan',
            'code' => 'sakit_ui_pimpinan',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $fixture['leave']->forceFill(['jenis_cuti_id' => $sickType->id])->save();

        $this->actingAs($fixture['user'])
            ->get(route('pimpinan.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertSee('Tunda Sementara')
            ->assertDontSee(route('pimpinan.cuti.penangguhan-tugas-dinas', $fixture['leave']), false);

        $fixture['leave']->forceFill(['jenis_cuti_id' => $annualTypeId])->save();
        $this->actingAs($fixture['user'])
            ->post(route('pimpinan.cuti.penangguhan-tugas-dinas', $fixture['leave']), ['alasan' => 'Penugasan mendesak mewakili instansi.']);

        $response = $this->actingAs($fixture['user'])
            ->get(route('pimpinan.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertSeeInOrder([
                'Timeline Persetujuan',
                'Tahap 1 · PYBMC',
                $fixture['approver']->nama_lengkap,
                'Ditangguhkan karena Tugas Dinas',
                'Tahap 2 · Kepala Lembaga',
                'Dilewati',
                'Dilewati karena penangguhan tugas dinas menutup pengajuan.',
                'Riwayat Tindakan Resmi',
                'Tahap 1 · '.$fixture['approver']->nama_lengkap,
                'Ditangguhkan karena Tugas Dinas',
            ])
            ->assertDontSee('ditangguhkan_tugas_dinas')
            ->assertDontSee('duty_postponement_terminal')
            ->assertDontSee(route('pimpinan.cuti.penangguhan-tugas-dinas', $fixture['leave']), false);
    }

    public function test_duty_postponement_pimpinan_detail_localizes_other_skipped_reasons(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $steps = $fixture['leave']->steps()->orderBy('step_order')->get();
        $steps[0]->forceFill(['status' => 'skipped', 'skipped_reason' => 'duplicate_approver'])->save();
        $steps[1]->forceFill(['status' => 'skipped', 'skipped_reason' => 'request_not_approved'])->save();
        $fixture['leave']->forceFill(['status' => 'tidak_disetujui'])->save();

        $this->actingAs($fixture['user'])
            ->get(route('pimpinan.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertSeeInOrder([
                'Timeline Persetujuan',
                'Tahap 1 · PYBMC',
                'Dilewati karena approver yang sama sudah tercakup pada tahap lain.',
                'Tahap 2 · Kepala Lembaga',
                'Dilewati karena pengajuan telah diputus tidak disetujui.',
            ])
            ->assertDontSee('duplicate_approver')
            ->assertDontSee('request_not_approved');
    }

    public function test_duty_postponement_pimpinan_invalid_reason_reopens_dedicated_modal_and_focuses_error(): void
    {
        $fixture = $this->dutyPostponementFixture();

        $this->actingAs($fixture['user'])
            ->from(route('pimpinan.cuti.show', $fixture['leave']))
            ->followingRedirects()
            ->post(route('pimpinan.cuti.penangguhan-tugas-dinas', $fixture['leave']), ['alasan' => 'abcd'])
            ->assertOk()
            ->assertSee('dutyPostponementOpen: true', false)
            ->assertSee('Alasan tugas dinas minimal berisi 5 karakter.')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('data-error-autofocus="true"', false)
            ->assertSee("document.getElementById('pimpinan-duty-postponement-reason')?.focus()", false)
            ->assertSee('confirmOpen: false', false);
    }

    public function test_duty_postponement_pimpinan_detail_uses_neutral_fallbacks_for_unknown_step_and_action(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $step = $fixture['leave']->steps()->orderBy('step_order')->first();
        $step->forceFill(['status' => 'step_rahasia_pimpinan'])->save();
        LeaveApproval::create([
            'leave_request_id' => $fixture['leave']->id,
            'approver_id' => $fixture['approver']->id,
            'stage' => 1,
            'action' => 'ACTION_RAHASIA_PIMPINAN',
            'komentar' => 'Fallback action pimpinan.',
            'acted_at' => now(),
        ]);

        $response = $this->actingAs($fixture['user'])
            ->get(route('pimpinan.cuti.show', $fixture['leave']));

        $response->assertOk()
            ->assertSeeInOrder([
                'Timeline Persetujuan',
                'Tahap 1 · PYBMC',
                $fixture['approver']->nama_lengkap,
                'Status tidak tersedia',
            ])
            ->assertSeeInOrder([
                'Riwayat Tindakan Resmi',
                'Tahap 1 · '.$fixture['approver']->nama_lengkap,
                'Tindakan tidak dikenal',
            ])
            ->assertDontSee('step_rahasia_pimpinan')
            ->assertDontSee('ACTION_RAHASIA_PIMPINAN');
        $this->assertSame(1, substr_count($response->getContent(), 'Status tidak tersedia'));
    }

    public function test_pimpinan_approval_uses_the_leave_workflow_and_records_the_decision(): void
    {
        $pemohon = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $pimpinan = User::factory()->pimpinan()->create(['employee_id' => $approver->id]);
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'cuti_sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
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

        $this->actingAs($pimpinan)
            ->post(route('pimpinan.cuti.decision', $leave), [
                'keputusan' => 'DISETUJUI',
                'catatan' => 'Disetujui.',
            ])
            ->assertRedirect(route('pimpinan.cuti.show', $leave))
            ->assertSessionHas('success', 'Pengajuan cuti berhasil disetujui.');

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leave->id,
            'status' => 'disetujui',
        ]);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leave->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'APPROVE',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'APPROVE',
            'auditable_type' => 'LeaveRequest',
            'auditable_id' => $leave->id,
        ]);
    }

    public function test_pimpinan_decline_uses_the_final_not_approved_contract(): void
    {
        $pemohon = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $pimpinan = User::factory()->pimpinan()->create(['employee_id' => $approver->id]);
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'cuti_sakit_pimpinan_decline',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
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

        $this->actingAs($pimpinan)
            ->post(route('pimpinan.cuti.decision', $leave), [
                'keputusan' => 'TIDAK_DISETUJUI',
                'catatan' => 'Dokumen pendukung tidak sesuai.',
            ])
            ->assertRedirect(route('pimpinan.cuti.show', $leave));

        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'status' => 'tidak_disetujui']);
        $this->assertDatabaseHas('leave_request_steps', [
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'status' => 'tidak_disetujui',
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leave->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'NOT_APPROVED',
        ]);
    }

    public function test_leave_detail_uses_the_actual_request_and_active_approval_step(): void
    {
        $pemohon = Employee::factory()->create(['nama_lengkap' => 'Pegawai Cuti Aktual']);
        $approver = Employee::factory()->create();
        $pimpinan = User::factory()->pimpinan()->create(['employee_id' => $approver->id]);
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'cuti_sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
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
        LeaveBalance::create([
            'employee_id' => $pemohon->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 3,
            'sisa' => 9,
        ]);

        $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.show', $leave))
            ->assertOk()
            ->assertSee('Pegawai Cuti Aktual')
            ->assertSee('Cuti Sakit')
            ->assertSee('9 Hari')
            ->assertSee(route('pimpinan.cuti.decision', $leave), false);
    }

    public function test_leave_index_lists_real_requests_and_links_to_the_request_detail(): void
    {
        $approver = Employee::factory()->create(['nama_lengkap' => 'Pejabat Berbeda Dari Label']);
        $pimpinan = User::factory()->pimpinan()->create(['employee_id' => $approver->id]);
        $pemohon = Employee::factory()->create(['nama_lengkap' => 'Pegawai Antrean Cuti']);
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Sakit Kontrak Daftar Pimpinan',
            'code' => 'cuti_sakit_kontrak_daftar_pimpinan',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'verifikator_kontrak_pimpinan',
            'role_label' => 'Verifikator Kontrak Pimpinan',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => false,
        ]);

        $response = $this->actingAs($pimpinan)
            ->get(route('pimpinan.cuti.index'));
        $row = $response->viewData('leaves')->getCollection()->firstWhere('id', $leave->id);

        $response
            ->assertOk()
            ->assertSee($pemohon->nama_lengkap)
            ->assertSee($jenisCuti->nama)
            ->assertSee($leave->id)
            ->assertSee('aria-label="Detail pengajuan cuti Pegawai Antrean Cuti"', false);
        $this->assertSame(1, $response->viewData('leaves')->total());
        $this->assertArrayHasKey('current_step_label', $row->getAttributes());
        $this->assertSame('Verifikator Kontrak Pimpinan', $row->getAttribute('current_step_label'));
        $this->assertArrayNotHasKey('activeStep', $row->getAttributes());
    }

    public function test_leave_index_renders_tahap_aktif_role_label_and_dash_for_terminal_request(): void
    {
        $pimpinan = User::factory()->pimpinan()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Kontrak Kolom Tahap Pimpinan',
            'code' => 'cuti_kontrak_kolom_tahap_pimpinan',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $activeLeave = LeaveRequest::create([
            'employee_id' => Employee::factory()->create(['nama_lengkap' => 'Pegawai Antrean Berjalan Pimpinan'])->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-09-07',
            'tanggal_selesai' => '2026-09-08',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Uji label snapshot Pimpinan.',
            'status' => 'menunggu_approval',
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $activeLeave->id,
            'step_order' => 1,
            'step_type' => 'pybmc_kontrak_tampilan',
            'role_label' => 'PYBMC Kontrak Tampilan',
            'approver_employee_id' => Employee::factory()->create(['nama_lengkap' => 'Nama Pejabat Bukan Label'])->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        $terminalLeave = LeaveRequest::create([
            'employee_id' => Employee::factory()->create(['nama_lengkap' => 'Pegawai Tahap Terminal Pimpinan'])->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-09-09',
            'tanggal_selesai' => '2026-09-09',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji tahap terminal Pimpinan.',
            'status' => 'disetujui',
        ]);

        $response = $this->actingAs($pimpinan)->get(route('pimpinan.cuti.index', ['status' => 'all']));

        $response->assertOk()
            ->assertSee('PYBMC Kontrak Tampilan');
        $this->assertSame(2, $response->viewData('leaves')->total());
        $this->assertMatchesRegularExpression(
            '/Pegawai Tahap Terminal Pimpinan.*?<span class="text-muted">-<\/span>/s',
            $response->getContent(),
        );
    }

    public function test_terminal_leave_index_row_exposes_a_null_current_step_label_without_active_step_alias(): void
    {
        $pimpinan = User::factory()->pimpinan()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Kontrak Terminal Pimpinan',
            'code' => 'cuti_kontrak_terminal_pimpinan',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => Employee::factory()->create(['nama_lengkap' => 'Pegawai Terminal Kontrak Pimpinan'])->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-09-10',
            'tanggal_selesai' => '2026-09-10',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji kontrak terminal daftar Pimpinan.',
            'status' => 'disetujui',
        ]);

        $response = $this->actingAs($pimpinan)->get(route('pimpinan.cuti.index', ['status' => 'all']));
        $row = $response->viewData('leaves')->getCollection()->firstWhere('id', $leave->id);

        $response->assertOk();
        $this->assertSame(1, $response->viewData('leaves')->total());
        $this->assertArrayHasKey('current_step_label', $row->getAttributes());
        $this->assertNull($row->getAttribute('current_step_label'));
        $this->assertArrayNotHasKey('activeStep', $row->getAttributes());
    }

    public function test_leave_index_has_a_tahap_aktif_column(): void
    {
        $response = $this->actingAs(User::factory()->pimpinan()->create())
            ->get(route('pimpinan.cuti.index'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<th\b[^>]*>\s*Tahap Aktif\s*<\/th>/s',
            $response->getContent(),
        );
    }

    /** @return array{user: User, approver: Employee, leave: LeaveRequest} */
    private function dutyPostponementFixture(): array
    {
        $applicant = Employee::factory()->create();
        $applicantUser = User::factory()->pegawai()->create(['employee_id' => $applicant->id]);
        $approver = Employee::factory()->create();
        $user = User::factory()->pimpinan()->create(['employee_id' => $approver->id]);
        $type = RefJenisCuti::firstOrCreate(['code' => 'tahunan'], [
            'nama' => 'Cuti Tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $balance = LeaveBalance::create([
            'employee_id' => $applicant->id, 'tahun' => 2026, 'jatah_awal' => 12, 'carry_over' => 0,
            'terpakai' => 0, 'sisa' => 12, 'sisa_n2' => 0, 'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12, 'terpakai_tahun_berjalan' => 0, 'hangus' => 0,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $applicant->id, 'jenis_cuti_id' => $type->id,
            'tanggal_mulai' => '2026-08-03', 'tanggal_selesai' => '2026-08-07',
            'jumlah_hari_kerja' => 5, 'alasan' => 'Cuti tahunan keluarga.', 'status' => 'menunggu_approval',
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id, 'step_order' => 1, 'step_type' => 'pybmc',
            'role_label' => 'PYBMC', 'approver_employee_id' => $approver->id, 'status' => 'active', 'is_final' => false,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id, 'step_order' => 2, 'step_type' => 'kepala_lembaga',
            'role_label' => 'Kepala Lembaga', 'approver_employee_id' => Employee::factory()->create()->id,
            'status' => 'pending', 'is_final' => true,
        ]);
        LeaveBalanceReservationEvent::create([
            'employee_id' => $applicant->id, 'leave_request_id' => $leave->id, 'leave_balance_id' => $balance->id,
            'tahun' => 2026, 'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED, 'amount' => 5,
            'dedup_key' => "leave_reservation:{$leave->id}:reserved", 'created_by' => $applicantUser->id,
            'occurred_at' => Carbon::parse('2026-08-01 08:00:00'),
        ]);

        return compact('user', 'approver', 'leave');
    }
}
