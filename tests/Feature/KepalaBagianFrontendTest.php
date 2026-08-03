<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
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

    public function test_status_bawahan_hanya_menampilkan_aktif_atau_cuti(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan Dengan Status Legacy',
            'kepala_bagian_id' => $kepalaBagian->id,
            'status_aktif' => 'Pensiun',
        ]);

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.index'))
            ->assertOk()
            ->assertSee('Bawahan Dengan Status Legacy')
            ->assertSee('Aktif')
            ->assertDontSee('Pensiun')
            ->assertDontSee('Dinas Luar');

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.show', $directReport))
            ->assertOk()
            ->assertSee('Aktif')
            ->assertDontSee('Pensiun')
            ->assertDontSee('Dinas Luar');

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.index', ['status' => 'aktif']))
            ->assertOk()
            ->assertSee('Bawahan Dengan Status Legacy');
    }

    public function test_dinas_luar_filter_is_rejected_for_kepala_bagian(): void
    {
        [$user] = $this->kepalaBagian();

        $this->actingAs($user)
            ->getJson(route('kepala-bagian.bawahan.index', ['status' => 'dinas_luar']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_legacy_status_employee_is_presented_as_active_without_raw_status_payload(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan Dengan Status Legacy',
            'kepala_bagian_id' => $kepalaBagian->id,
            'status_aktif' => 'Pensiun',
        ]);

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.index'))
            ->assertOk()
            ->assertViewHas('employees', function ($employees): bool {
                $employee = $employees->firstWhere('nama_lengkap', 'Bawahan Dengan Status Legacy');

                return $employee !== null
                    && $employee->getAttribute('status_tampilan') === 'Aktif'
                    && ! array_key_exists('status_aktif', $employee->getAttributes())
                    && ! array_key_exists('status_pegawai_id', $employee->getAttributes())
                    && ! $employee->relationLoaded('statusPegawai');
            });

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.show', $directReport))
            ->assertOk()
            ->assertViewHas('employee', function (Employee $employee): bool {
                return $employee->getAttribute('status_tampilan') === 'Aktif'
                    && ! array_key_exists('status_aktif', $employee->getAttributes())
                    && ! array_key_exists('status_pegawai_id', $employee->getAttributes())
                    && ! $employee->relationLoaded('statusPegawai');
            });
    }

    public function test_current_approved_leave_displays_cuti_in_list_and_detail(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create([
            'nama_lengkap' => 'Bawahan Sedang Cuti',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $leave = $this->leaveWithActiveStep($directReport, $kepalaBagian);
        $leave->forceFill([
            'tanggal_mulai' => now()->subDay()->toDateString(),
            'tanggal_selesai' => now()->addDay()->toDateString(),
            'status' => 'disetujui',
        ])->save();

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.index'))
            ->assertOk()
            ->assertSeeInOrder(['Bawahan Sedang Cuti', 'Cuti']);

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.show', $directReport))
            ->assertOk()
            ->assertSeeInOrder(['Bawahan Sedang Cuti', 'Cuti']);

        $this->actingAs($user)
            ->get(route('kepala-bagian.bawahan.index', ['status' => 'aktif']))
            ->assertOk()
            ->assertDontSee('Bawahan Sedang Cuti');
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

    public function test_leave_index_defaults_to_menunggu_approval_status(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $pendingReport = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Menunggu',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $approvedReport = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Disetujui',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);

        $pendingLeave = $this->leaveWithActiveStep($pendingReport, $kepalaBagian);

        $approvedLeave = LeaveRequest::create([
            'employee_id' => $approvedReport->id,
            'jenis_cuti_id' => $pendingLeave->jenis_cuti_id,
            'tanggal_mulai' => '2026-06-01',
            'tanggal_selesai' => '2026-06-02',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Sudah disetujui sebelumnya.',
            'status' => 'disetujui',
        ]);

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.index'))
            ->assertOk()
            ->assertSee('Pemohon Menunggu')
            ->assertDontSee('Pemohon Disetujui');

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee('Pemohon Menunggu')
            ->assertSee('Pemohon Disetujui');
    }

    public function test_leave_index_accepts_and_labels_duty_postponement_status(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pemohon Terminal Kepala Bagian',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        $leave = $this->leaveWithActiveStep($employee, $kepalaBagian);
        $leave->forceFill(['status' => LeaveRequest::STATUS_DUTY_POSTPONED])->save();

        $this->actingAs($user)
            ->get(route('kepala-bagian.cuti.index', ['status' => LeaveRequest::STATUS_DUTY_POSTPONED]))
            ->assertOk()
            ->assertSee('Pemohon Terminal Kepala Bagian')
            ->assertSee('Ditangguhkan karena Tugas Dinas')
            ->assertSee('value="'.LeaveRequest::STATUS_DUTY_POSTPONED.'"', false);
    }

    public function test_detail_pending_step_explains_waiting_role(): void
    {
        $fixture = $this->dutyPostponementFixture();

        $this->actingAs($fixture['user'])
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertSeeInOrder(['Tahap 2 · PYBMC', 'Menunggu PYBMC']);
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

    public function test_duty_postponement_kepala_bagian_route_records_terminal_workflow(): void
    {
        $fixture = $this->dutyPostponementFixture();

        $this->actingAs($fixture['user'])
            ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), ['alasan' => 'Penugasan mendesak mewakili instansi.'])
            ->assertRedirect(route('kepala-bagian.cuti.show', $fixture['leave']))
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

    public function test_duty_postponement_kepala_bagian_route_validates_reason_without_mutation(): void
    {
        foreach ([
            '' => 'Alasan tugas dinas mendesak wajib diisi.',
            'abcd' => 'Alasan tugas dinas minimal berisi 5 karakter.',
            str_repeat('a', 501) => 'Alasan tugas dinas maksimal berisi 500 karakter.',
        ] as $reason => $message) {
            $fixture = $this->dutyPostponementFixture();
            $this->actingAs($fixture['user'])
                ->from(route('kepala-bagian.cuti.show', $fixture['leave']))
                ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), ['alasan' => $reason])
                ->assertRedirect(route('kepala-bagian.cuti.show', $fixture['leave']))
                ->assertSessionHasErrorsIn('dutyPostponement', ['alasan' => $message]);
            $this->assertSame('menunggu_approval', $fixture['leave']->fresh()->status);
            $this->assertDatabaseMissing('leave_approvals', ['leave_request_id' => $fixture['leave']->id]);
        }
    }

    public function test_duty_postponement_kepala_bagian_route_rejects_malformed_uuid(): void
    {
        [$user] = $this->kepalaBagian();
        $this->actingAs($user)
            ->post('/kepala-bagian/cuti/bukan-uuid/penangguhan-tugas-dinas', ['alasan' => 'Penugasan mendesak mewakili instansi.'])
            ->assertNotFound();
    }

    public function test_duty_postponement_kepala_bagian_route_rejects_non_snapshot_actor_without_mutation(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $other = Employee::factory()->create();
        $fixture['leave']->employee->forceFill(['kepala_bagian_id' => $other->id])->save();
        $user = User::factory()->kepalaBagian()->create(['employee_id' => $other->id]);

        $this->actingAs($user)
            ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), ['alasan' => 'Penugasan mendesak mewakili instansi.'])
            ->assertForbidden();
        $this->assertSame('menunggu_approval', $fixture['leave']->fresh()->status);
    }

    public function test_duty_postponement_kepala_bagian_route_rejects_request_outside_direct_report_scope(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $fixture['leave']->employee->forceFill(['kepala_bagian_id' => Employee::factory()->create()->id])->save();

        $this->actingAs($fixture['user'])
            ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), ['alasan' => 'Penugasan mendesak mewakili instansi.'])
            ->assertForbidden();
        $this->assertSame('menunggu_approval', $fixture['leave']->fresh()->status);
    }

    public function test_duty_postponement_kepala_bagian_detail_shows_distinct_actions_only_for_eligible_actor(): void
    {
        $fixture = $this->dutyPostponementFixture();

        $this->actingAs($fixture['user'])
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertSee('Tunda Sementara')
            ->assertSee('Tangguhkan karena Tugas Dinas')
            ->assertSee(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), false)
            ->assertSee('name="alasan"', false)
            ->assertSee('minlength="5"', false)
            ->assertSee('maxlength="500"', false)
            ->assertSee('Konfirmasi Penangguhan Tugas Dinas')
            ->assertSee('openDutyPostponement($event)', false)
            ->assertSee('closeDutyPostponement()', false)
            ->assertSee("document.getElementById('kabag-duty-postponement-reason')?.focus()", false)
            ->assertSee('dutyPostponementTrigger?.focus()', false)
            ->assertSee('aria-describedby="kabag-duty-postponement-description"', false);

        $other = Employee::factory()->create();
        $fixture['leave']->employee->forceFill(['kepala_bagian_id' => $other->id])->save();
        $otherUser = User::factory()->kepalaBagian()->create(['employee_id' => $other->id]);
        $this->actingAs($otherUser)
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertDontSee(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), false);
    }

    public function test_duty_postponement_kepala_bagian_detail_hides_action_for_non_annual_and_renders_terminal_history(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $annualTypeId = $fixture['leave']->jenis_cuti_id;
        $sickType = RefJenisCuti::create([
            'nama' => 'Cuti Sakit UI Kepala Bagian',
            'code' => 'sakit_ui_kepala_bagian',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $fixture['leave']->forceFill(['jenis_cuti_id' => $sickType->id])->save();

        $this->actingAs($fixture['user'])
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertSee('Tunda Sementara')
            ->assertDontSee(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), false);

        $fixture['leave']->forceFill(['jenis_cuti_id' => $annualTypeId])->save();
        $this->actingAs($fixture['user'])
            ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), ['alasan' => 'Penugasan mendesak mewakili instansi.']);

        $persistedSteps = $fixture['leave']->steps()->with('approver')->orderBy('step_order')->get();
        $persistedSteps[0]->approver->forceFill(['nama_lengkap' => 'Approver Tugas Dinas Kepala Bagian'])->save();
        $persistedSteps[1]->approver->forceFill(['nama_lengkap' => 'Approver Tahap Lanjutan Kepala Bagian'])->save();

        $response = $this->actingAs($fixture['user'])
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertOk();

        $response
            ->assertSeeInOrder([
                'Timeline',
                'Persetujuan',
                'Tahap 1 · Kepala Bagian',
                'Approver Tugas Dinas Kepala Bagian',
                'Ditangguhkan karena Tugas Dinas',
            ])
            ->assertSeeInOrder([
                'Timeline',
                'Persetujuan',
                'Tahap 2 · PYBMC',
                'Approver Tahap Lanjutan Kepala Bagian',
                'Dilewati',
                'Dilewati karena penangguhan tugas dinas menutup pengajuan.',
            ])
            ->assertSeeInOrder([
                'Riwayat Tindakan Resmi',
                'Tahap 1 · Approver Tugas Dinas Kepala Bagian',
                'Ditangguhkan karena Tugas Dinas',
            ])
            ->assertDontSee('ditangguhkan_tugas_dinas')
            ->assertDontSee('duty_postponement_terminal')
            ->assertDontSee(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), false);
    }

    public function test_duty_postponement_kepala_bagian_detail_localizes_other_skipped_reasons(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $steps = $fixture['leave']->steps()->orderBy('step_order')->get();
        $steps[0]->forceFill(['status' => 'skipped', 'skipped_reason' => 'duplicate_approver'])->save();
        $steps[1]->forceFill(['status' => 'skipped', 'skipped_reason' => 'request_not_approved'])->save();
        $fixture['leave']->forceFill(['status' => 'tidak_disetujui'])->save();

        $this->actingAs($fixture['user'])
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->assertOk()
            ->assertSeeInOrder([
                'Timeline',
                'Persetujuan',
                'Tahap 1 · Kepala Bagian',
                'Dilewati karena approver yang sama sudah tercakup pada tahap lain.',
                'Tahap 2 · PYBMC',
                'Dilewati karena pengajuan telah diputus tidak disetujui.',
            ])
            ->assertDontSee('duplicate_approver')
            ->assertDontSee('request_not_approved');
    }

    public function test_duty_postponement_kepala_bagian_invalid_reason_reopens_dedicated_modal_and_focuses_error(): void
    {
        $fixture = $this->dutyPostponementFixture();

        $this->actingAs($fixture['user'])
            ->from(route('kepala-bagian.cuti.show', $fixture['leave']))
            ->followingRedirects()
            ->post(route('kepala-bagian.cuti.penangguhan-tugas-dinas', $fixture['leave']), ['alasan' => 'abcd'])
            ->assertOk()
            ->assertSee('dutyPostponementOpen: true', false)
            ->assertSee('Alasan tugas dinas minimal berisi 5 karakter.')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('data-error-autofocus="true"', false)
            ->assertSee("document.getElementById('kabag-duty-postponement-reason')?.focus()", false)
            ->assertSee('confirmOpen: false', false);
    }

    public function test_duty_postponement_kepala_bagian_detail_uses_neutral_unknown_fallbacks(): void
    {
        $fixture = $this->dutyPostponementFixture();
        $fixture['leave']->forceFill(['status' => 'status_rahasia_kabag'])->save();
        $step = $fixture['leave']->steps()->orderBy('step_order')->first();
        $step->forceFill(['status' => 'step_rahasia_kabag'])->save();
        LeaveApproval::create([
            'leave_request_id' => $fixture['leave']->id,
            'approver_id' => $fixture['approver']->id,
            'stage' => 1,
            'action' => 'ACTION_RAHASIA_KABAG',
            'komentar' => 'Fallback action kepala bagian.',
            'acted_at' => now(),
        ]);

        $response = $this->actingAs($fixture['user'])
            ->get(route('kepala-bagian.cuti.show', $fixture['leave']));

        $response->assertOk()
            ->assertSeeInOrder([
                'Timeline',
                'Persetujuan',
                'Tahap 1 · Kepala Bagian',
                'Status tidak tersedia',
            ])
            ->assertSeeInOrder([
                'Riwayat Tindakan Resmi',
                'Tahap 1 · ',
                'Tindakan tidak dikenal',
            ])
            ->assertDontSee('status_rahasia_kabag')
            ->assertDontSee('step_rahasia_kabag')
            ->assertDontSee('ACTION_RAHASIA_KABAG');
        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), 'Status tidak tersedia'));
    }

    public function test_detail_cuti_bawahan_menampilkan_status_tidak_disetujui(): void
    {
        [$user, $kepalaBagian] = $this->kepalaBagian();
        $directReport = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $leave = $this->leaveWithActiveStep($directReport, $kepalaBagian);
        $leave->forceFill(['status' => 'tidak_disetujui'])->save();
        $leave->steps()->delete();

        LeaveRequestStep::create([
            'leave_request_id' => $leave->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian Baru',
            'approver_employee_id' => $kepalaBagian->id,
            'status' => 'tidak_disetujui',
            'is_final' => true,
            'acted_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('kepala-bagian.cuti.show', $leave));

        $response
            ->assertOk()
            ->assertSeeInOrder(['Kepala Bagian Baru', 'Tidak Disetujui'])
            ->assertDontSee('Kepala Bagian Legacy')
            ->assertDontSee('Ditolak')
            ->assertDontSee('Rejected');
        $this->assertGreaterThanOrEqual(1, substr_count($response->getContent(), 'border-danger'));
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

    /** @return array{user: User, approver: Employee, leave: LeaveRequest} */
    private function dutyPostponementFixture(): array
    {
        [$user, $approver] = $this->kepalaBagian();
        $applicant = Employee::factory()->create(['kepala_bagian_id' => $approver->id]);
        $applicantUser = User::factory()->pegawai()->create(['employee_id' => $applicant->id]);
        $type = RefJenisCuti::firstOrCreate(['code' => 'tahunan'], [
            'nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false,
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
            'leave_request_id' => $leave->id, 'step_order' => 1, 'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian', 'approver_employee_id' => $approver->id, 'status' => 'active', 'is_final' => false,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leave->id, 'step_order' => 2, 'step_type' => 'pybmc',
            'role_label' => 'PYBMC', 'approver_employee_id' => Employee::factory()->create()->id,
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
