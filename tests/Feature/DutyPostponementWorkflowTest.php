<?php

namespace Tests\Feature;

use App\Actions\Cuti\RecordDutyPostponementAction;
use App\Data\Cuti\CutiRekapReadRow;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use App\Models\RefNotificationChannel;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\LeaveApprovalService;
use App\Support\Cuti\CutiReportStatusFormatter;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;
use Throwable;

class DutyPostponementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Penugasan mendesak untuk mewakili instansi.';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(RbacSeeder::class);
    }

    public function test_duty_postponement_generic_route_records_terminal_workflow_and_ignores_client_facts(): void
    {
        $fixture = $this->makeWorkflowFixture();

        $this->actingAs($fixture['actingUser'])
            ->post(route('cuti.penangguhan-tugas-dinas', $fixture['request']), [
                'active_step_id' => $fixture['activeStep']->id,
                'alasan' => self::REASON,
                'employee_id' => Employee::factory()->create()->id,
                'source_year' => 2030,
                'jumlah_hari' => 99,
                'actor_id' => Employee::factory()->create()->id,
            ])
            ->assertRedirect(route('cuti.approval'))
            ->assertSessionHas('success', 'Cuti Tahunan ditangguhkan karena tugas dinas dan hak terkait telah dilindungi untuk satu tahun berikutnya.');

        $this->assertDatabaseHas('leave_requests', [
            'id' => $fixture['request']->id,
            'employee_id' => $fixture['applicant']->id,
            'jumlah_hari_kerja' => 5,
            'status' => LeaveRequest::STATUS_DUTY_POSTPONED,
        ]);
        $this->assertDatabaseHas('leave_balance_ledger', [
            'leave_request_id' => $fixture['request']->id,
            'source_year' => 2026,
            'created_by' => $fixture['actingUser']->id,
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $fixture['request']->id,
            'approver_id' => $fixture['actor']->id,
            'action' => LeaveApproval::ACTION_DUTY_POSTPONEMENT,
        ]);
    }

    public function test_duty_postponement_generic_route_validates_reason_without_mutation(): void
    {
        foreach ([
            '' => 'Alasan tugas dinas mendesak wajib diisi.',
            'abcd' => 'Alasan tugas dinas minimal berisi 5 karakter.',
            str_repeat('a', 501) => 'Alasan tugas dinas maksimal berisi 500 karakter.',
        ] as $reason => $message) {
            $fixture = $this->makeWorkflowFixture();
            $before = $this->workflowState($fixture['request']->id, $fixture['balance']);

            $this->actingAs($fixture['actingUser'])
                ->from(route('cuti.approval'))
                ->post(route('cuti.penangguhan-tugas-dinas', $fixture['request']), [
                    'active_step_id' => $fixture['activeStep']->id,
                    'alasan' => $reason,
                ])
                ->assertRedirect(route('cuti.approval'))
                ->assertSessionHasErrorsIn('dutyPostponement', ['alasan' => $message]);

            $this->assertSame($before, $this->workflowState($fixture['request']->id, $fixture['balance']->fresh()));
        }
    }

    public function test_duty_postponement_generic_route_rejects_malformed_uuid(): void
    {
        $this->actingAs(User::factory()->kepalaBagian()->create(['employee_id' => Employee::factory()->create()->id]))
            ->post('/cuti/bukan-uuid/penangguhan-tugas-dinas', ['alasan' => self::REASON])
            ->assertNotFound();
    }

    public function test_duty_postponement_generic_route_rejects_non_snapshot_actor_without_mutation(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $before = $this->workflowState($fixture['request']->id, $fixture['balance']);
        $other = Employee::factory()->create();
        $user = User::factory()->kepalaBagian()->create(['employee_id' => $other->id]);

        $this->actingAs($user)
            ->post(route('cuti.penangguhan-tugas-dinas', $fixture['request']), [
                'active_step_id' => $fixture['activeStep']->id,
                'alasan' => self::REASON,
            ])
            ->assertForbidden();

        $this->assertSame($before, $this->workflowState($fixture['request']->id, $fixture['balance']->fresh()));
    }

    public function test_duty_postponement_admin_detail_distinguishes_temporary_and_terminal_actions(): void
    {
        $fixture = $this->makeWorkflowFixture();

        $this->actingAs($fixture['actingUser'])
            ->get(route('cuti.show', $fixture['request']))
            ->assertOk()
            ->assertSeeInOrder([
                'data-action-visual="temporary-secondary"',
                'Tangguhkan',
                'Penangguhan karena tugas dinas menutup pengajuan lama dan melindungi hak sesuai ketentuan.',
                'data-action-visual="terminal-warning"',
                'Tangguhkan karena Tugas Dinas',
            ], false)
            ->assertSee('flex flex-wrap items-end justify-end gap-3', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('aria-describedby="decision-description-duty-postponement"', false)
            ->assertSee('class="fixed inset-0 bg-ink/50 transition-opacity', false)
            ->assertSee(route('cuti.penangguhan-tugas-dinas', $fixture['request']), false)
            ->assertSee('name="alasan"', false)
            ->assertSee('minlength="5"', false)
            ->assertSee('maxlength="500"', false)
            ->assertSee('aria-describedby="decision-description-duty-postponement alasan-duty-postponement-help', false)
            ->assertSee('@keydown.escape.window="if (decisionForm === &#039;dutyPostponement&#039;) { close() }"', false)
            ->assertDontSee('@keydown.escape.window="close()"', false)
            ->assertSee('@keydown.escape.window="if (decisionForm !== null) close()"', false)
            ->assertSee('this.$nextTick(() => this.lastTrigger?.focus())', false)
            ->assertSee('Konfirmasi Penangguhan Tugas Dinas');
    }

    public function test_duty_postponement_admin_detail_hides_terminal_action_when_not_eligible(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $sickType = RefJenisCuti::create([
            'nama' => 'Cuti Sakit UI Admin',
            'code' => 'sakit_ui_admin',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $fixture['request']->forceFill(['jenis_cuti_id' => $sickType->id])->save();

        $this->actingAs($fixture['actingUser'])
            ->get(route('cuti.show', $fixture['request']))
            ->assertOk()
            ->assertSee('Ditangguhkan')
            ->assertDontSee('Tunda Sementara')
            ->assertDontSee(route('cuti.penangguhan-tugas-dinas', $fixture['request']), false);

        $fixture['request']->forceFill([
            'jenis_cuti_id' => RefJenisCuti::where('code', 'tahunan')->value('id'),
        ])->save();
        $terminal = $this->action()->execute(
            $fixture['request'],
            $fixture['actor'],
            $fixture['actingUser'],
            $fixture['activeStep']->id,
            self::REASON,
        );

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti.show', $terminal))
            ->assertOk()
            ->assertSeeInOrder([
                'Alur Persetujuan Cuti',
                'Ditangguhkan karena Tugas Dinas oleh Atasan Langsung',
                $fixture['actor']->nama_lengkap,
                'Dilewati: PYBMC',
                $fixture['laterStep']->approver->nama_lengkap,
                'Dilewati karena penangguhan tugas dinas menutup pengajuan.',
                'Riwayat Tindakan Approval',
                'Ditangguhkan karena Tugas Dinas',
                $fixture['actor']->nama_lengkap,
                'Tahap 1 (Atasan Langsung)',
            ])
            ->assertDontSee('ditangguhkan_tugas_dinas')
            ->assertDontSee('duty_postponement_terminal')
            ->assertDontSee(route('cuti.penangguhan-tugas-dinas', $terminal), false);
    }

    public function test_duty_postponement_admin_detail_and_report_render_terminal_label(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $terminal = $this->action()->execute(
            $fixture['request'],
            $fixture['actor'],
            $fixture['actingUser'],
            $fixture['activeStep']->id,
            self::REASON,
        );

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti.show', $terminal))
            ->assertOk()
            ->assertSee('Ditangguhkan karena Tugas Dinas')
            ->assertDontSee(route('cuti.penangguhan-tugas-dinas', $terminal), false);

        $formatter = app(CutiReportStatusFormatter::class);
        $this->assertSame(
            'Ditangguhkan karena Tugas Dinas',
            $formatter->format($terminal->status, null, CutiRekapReadRow::SOURCE_LEAVE_REQUEST),
        );
        $terminal->forceFill(['status' => 'ditangguhkan']);
        $this->assertSame(
            'Ditangguhkan',
            $formatter->format($terminal->status, null, CutiRekapReadRow::SOURCE_LEAVE_REQUEST),
        );
    }

    public function test_duty_postponement_admin_detail_localizes_other_skipped_reasons(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $fixture['activeStep']->forceFill([
            'status' => 'skipped',
            'skipped_reason' => 'workflow_closed',
        ])->save();
        $fixture['laterStep']->forceFill([
            'status' => 'skipped',
            'skipped_reason' => 'request_not_approved',
        ])->save();
        $fixture['request']->forceFill(['status' => 'tidak_disetujui'])->save();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti.show', $fixture['request']))
            ->assertOk()
            ->assertSeeInOrder([
                'Alur Persetujuan Cuti',
                'Dilewati: Atasan Langsung',
                'Dilewati karena alur persetujuan telah ditutup.',
                'Dilewati: PYBMC',
                'Dilewati karena pengajuan telah diputus tidak disetujui.',
            ])
            ->assertDontSee('workflow_closed')
            ->assertDontSee('request_not_approved');
    }

    public function test_duty_postponement_admin_invalid_reason_reopens_dedicated_form_and_focuses_error(): void
    {
        $fixture = $this->makeWorkflowFixture();

        $this->actingAs($fixture['actingUser'])
            ->from(route('cuti.show', $fixture['request']))
            ->followingRedirects()
            ->post(route('cuti.penangguhan-tugas-dinas', $fixture['request']), [
                'active_step_id' => $fixture['activeStep']->id,
                'alasan' => 'abcd',
            ])
            ->assertOk()
            ->assertSee('decisionForm: &#039;dutyPostponement&#039;', false)
            ->assertSee('Alasan tugas dinas minimal berisi 5 karakter.')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('data-error-autofocus="true"', false)
            ->assertSee("x-init=\"\$nextTick(() => document.getElementById('alasan-duty-postponement')?.focus())\"", false)
            ->assertDontSee("const field = document.getElementById('alasan-duty-postponement'); field?.focus(); field?.scrollIntoView", false)
            ->assertDontSee("decisionForm: 'postpone'", false);
    }

    public function test_duty_postponement_admin_stale_token_reopens_dedicated_form_with_error(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $message = 'Tahap persetujuan telah berubah. Muat ulang halaman sebelum mengirim keputusan.';

        $this->actingAs($fixture['actingUser'])
            ->from(route('cuti.show', $fixture['request']))
            ->post(route('cuti.penangguhan-tugas-dinas', $fixture['request']), [
                'active_step_id' => '00000000-0000-4000-8000-000000000034',
                'alasan' => self::REASON,
            ])
            ->assertRedirect(route('cuti.show', $fixture['request']))
            ->assertSessionHasErrorsIn('dutyPostponement', ['active_step_id' => $message]);

        $this->get(route('cuti.show', $fixture['request']))
            ->assertOk()
            ->assertSee('decisionForm: &#039;dutyPostponement&#039;', false)
            ->assertSee($message);
    }

    public function test_duty_postponement_admin_default_error_bag_does_not_open_dedicated_form(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $errors = (new ViewErrorBag)->put('default', new MessageBag([
            'alasan' => ['Alasan pengajuan ulang wajib diperbaiki.'],
        ]));

        $this->actingAs($fixture['actingUser'])
            ->withSession(['errors' => $errors])
            ->get(route('cuti.show', $fixture['request']))
            ->assertOk()
            ->assertSee('decisionForm: null', false)
            ->assertSee('data-error-autofocus="false"', false)
            ->assertDontSee('decisionForm: &#039;dutyPostponement&#039;', false);
    }

    public function test_duty_postponement_admin_detail_uses_neutral_fallbacks_for_unknown_step_and_action(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $fixture['activeStep']->forceFill(['status' => 'step_rahasia_admin'])->save();
        LeaveApproval::create([
            'leave_request_id' => $fixture['request']->id,
            'approver_id' => $fixture['actor']->id,
            'stage' => 1,
            'action' => 'ACTION_RAHASIA_ADMIN',
            'komentar' => 'Fallback action admin.',
            'acted_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('cuti.show', $fixture['request']));

        $response->assertOk()
            ->assertSeeInOrder([
                'Alur Persetujuan Cuti',
                'Status tidak tersedia',
                $fixture['actor']->nama_lengkap,
            ])
            ->assertSeeInOrder([
                'Riwayat Tindakan Approval',
                'Tindakan tidak dikenal',
                $fixture['actor']->nama_lengkap,
                'Tahap 1 (Atasan Langsung)',
            ])
            ->assertDontSee('step_rahasia_admin')
            ->assertDontSee('ACTION_RAHASIA_ADMIN');
        $this->assertSame(1, substr_count($response->getContent(), 'Status tidak tersedia'));
    }

    public function test_action_records_terminal_duty_postponement_atomically(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $summaryBefore = $this->balanceSummary($fixture['balance']);

        $result = app(RecordDutyPostponementAction::class)->execute(
            $fixture['request'],
            $fixture['actor'],
            $fixture['actingUser'],
            $fixture['activeStep']->id,
            self::REASON,
        );

        $this->assertSame(LeaveRequest::STATUS_DUTY_POSTPONED, $result->status);

        $activeStep = $fixture['activeStep']->fresh();
        $this->assertSame(LeaveRequestStep::STATUS_DUTY_POSTPONED, $activeStep->status);
        $this->assertSame(self::REASON, $activeStep->decision_note);
        $this->assertNotNull($activeStep->acted_at);

        $laterStep = $fixture['laterStep']->fresh();
        $this->assertSame('skipped', $laterStep->status);
        $this->assertSame(LeaveRequestStep::SKIPPED_DUTY_POSTPONEMENT_TERMINAL, $laterStep->skipped_reason);
        $this->assertSame(0, $result->steps()->whereIn('status', ['active', 'pending'])->count());

        $approval = LeaveApproval::query()
            ->where('leave_request_id', $result->id)
            ->where('action', LeaveApproval::ACTION_DUTY_POSTPONEMENT)
            ->sole();
        $this->assertSame($fixture['actor']->id, $approval->approver_id);
        $this->assertSame($activeStep->step_order, $approval->stage);
        $this->assertSame(self::REASON, $approval->komentar);
        $this->assertNotNull($approval->acted_at);

        $ledger = LeaveBalanceLedger::query()
            ->where('leave_request_id', $result->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)
            ->sole();
        $release = LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $result->id)
            ->where('event_type', LeaveBalanceReservationEvent::EVENT_RELEASED)
            ->sole();
        $this->assertSame(5, $ledger->metadata['protected_days']);
        $this->assertSame(5, $ledger->metadata['source_request_workdays']);
        $this->assertSame('menunggu_approval', $ledger->metadata['source_status']);
        $this->assertSame(-5, $release->amount);

        $audit = AuditLog::query()
            ->where('event', 'DUTY_POSTPONEMENT')
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $result->id)
            ->sole();
        $this->assertSame($result->id, $audit->new_values['request_id']);
        $this->assertSame($fixture['applicant']->id, $audit->new_values['employee_id']);
        $this->assertSame(2026, $audit->new_values['source_year']);
        $this->assertSame(5, $audit->new_values['protected_days']);
        $this->assertSame($fixture['actor']->id, $audit->new_values['actor_id']);
        $this->assertSame(self::REASON, $audit->new_values['reason']);
        $this->assertSame($ledger->id, $audit->new_values['ledger_id']);
        $this->assertSame($release->id, $audit->new_values['reservation_event_id']);
        $this->assertTrue($audit->new_values['in_app_notification_recorded']);

        $notification = SimpegNotification::query()
            ->where('user_id', $fixture['applicant']->id)
            ->where('type', 'cuti.ditangguhkan_tugas_dinas')
            ->sole();
        $this->assertSame('Cuti Tahunan Ditangguhkan karena Tugas Dinas', $notification->title);
        $this->assertSame(
            'Pengajuan Cuti Tahunan Anda ditutup karena tugas dinas mendesak. Hak yang memenuhi ketentuan dapat digunakan melalui pengajuan baru pada tahun berikutnya.',
            $notification->body,
        );
        $approval = LeaveApproval::query()->where('leave_request_id', $result->id)->sole();
        $this->assertSame([
            'leave_request_id' => $result->id,
            'leave_approval_id' => $approval->id,
            'source_year' => 2026,
            'protected_days' => 5,
            'url' => route('cuti.show', ['id' => $result->id], false),
        ], $notification->data);
        $this->assertStringStartsWith('/', $notification->data['url']);

        $this->assertSame(0, LeaveBalanceLedger::query()
            ->where('leave_request_id', $result->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED)
            ->count());
        $this->assertSame(0, LeaveProof::query()->where('leave_request_id', $result->id)->count());
        $this->assertSame($summaryBefore, $this->balanceSummary($fixture['balance']->fresh()));
        $this->assertSame(0, LeaveApproval::query()
            ->where('leave_request_id', $result->id)
            ->where('action', 'POSTPONE')
            ->count());
        $this->assertSame(1, LeaveApproval::query()->where('leave_request_id', $result->id)->count());
        $this->assertSame(1, AuditLog::query()->where('auditable_type', 'LeaveRequest')->where('auditable_id', $result->id)->count());
        $this->assertSame(1, SimpegNotification::query()->where('data->leave_request_id', $result->id)->count());
    }

    public function test_non_snapshot_approver_is_rejected_without_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $otherActor = Employee::factory()->create();
        $otherUser = User::factory()->kepalaBagian()->create(['employee_id' => $otherActor->id]);

        $this->assertRejectedWithoutEffect(
            $fixture,
            fn () => $this->action()->execute($fixture['request'], $otherActor, $otherUser, $fixture['activeStep']->id, self::REASON),
            AuthorizationException::class,
        );
    }

    public function test_acting_user_must_belong_to_actor_without_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $wrongUser = User::factory()->kepalaBagian()->create(['employee_id' => Employee::factory()->create()->id]);

        $this->assertRejectedWithoutEffect(
            $fixture,
            fn () => $this->action()->execute($fixture['request'], $fixture['actor'], $wrongUser, $fixture['activeStep']->id, self::REASON),
            AuthorizationException::class,
        );
    }

    public function test_non_annual_request_is_rejected_without_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $sickType = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'sakit_workflow',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $fixture['request']->forceFill(['jenis_cuti_id' => $sickType->id])->save();

        $this->assertRejectedWithoutEffect($fixture, $this->executeFixture($fixture), ValidationException::class);
    }

    public function test_non_actionable_request_is_rejected_without_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $fixture['request']->forceFill(['status' => 'tidak_disetujui'])->save();

        $this->assertRejectedWithoutEffect($fixture, $this->executeFixture($fixture), ValidationException::class);
    }

    public function test_request_without_active_step_is_rejected_without_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $fixture['activeStep']->forceFill(['status' => 'pending'])->save();

        $this->assertRejectedWithoutEffect($fixture, $this->executeFixture($fixture), ValidationException::class);
    }

    public function test_cross_year_request_is_rejected_without_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $fixture['request']->forceFill(['tanggal_selesai' => '2027-01-04'])->save();

        $this->assertRejectedWithoutEffect($fixture, $this->executeFixture($fixture), ValidationException::class);
    }

    public function test_non_positive_workdays_are_rejected_without_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();
        DB::table('leave_requests')->where('id', $fixture['request']->id)->update(['jumlah_hari_kerja' => 0]);

        $this->assertRejectedWithoutEffect($fixture, $this->executeFixture($fixture), ValidationException::class);
    }

    public function test_partial_active_reservation_is_rejected_without_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();
        DB::table('leave_balance_reservation_events')
            ->where('leave_request_id', $fixture['request']->id)
            ->update(['amount' => 4]);

        $this->assertRejectedWithoutEffect($fixture, $this->executeFixture($fixture), ValidationException::class);
    }

    public function test_effective_balance_after_other_reservations_must_be_sufficient_without_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $fixture['balance']->forceFill([
            'jatah_awal' => 10,
            'sisa' => 10,
            'sisa_tahun_berjalan' => 10,
        ])->save();
        $protectedRequest = LeaveRequest::create([
            'employee_id' => $fixture['applicant']->id,
            'jenis_cuti_id' => $fixture['request']->jenis_cuti_id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => '2026-07-01',
            'jumlah_hari_kerja' => 4,
            'alasan' => 'Hak yang sudah dilindungi.',
            'status' => LeaveRequest::STATUS_DUTY_POSTPONED,
        ]);
        LeaveBalanceLedger::create([
            'employee_id' => $fixture['applicant']->id,
            'leave_request_id' => $protectedRequest->id,
            'leave_balance_id' => $fixture['balance']->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED,
            'amount' => 0,
            'source_year' => 2026,
            'reason' => 'Perlindungan existing.',
            'dedup_key' => "duty_postponement:{$protectedRequest->id}",
            'metadata' => [
                'request_id' => $protectedRequest->id,
                'protected_days' => 4,
                'protected_allocations' => ['n2' => 0, 'n1' => 0, 'current' => 4],
                'expiry_policy' => 'valid_one_year_no_n2_aging',
            ],
            'created_by' => $fixture['actingUser']->id,
        ]);
        $otherRequest = LeaveRequest::create([
            'employee_id' => $fixture['applicant']->id,
            'jenis_cuti_id' => $fixture['request']->jenis_cuti_id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-01',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Reservasi request lain.',
            'status' => 'menunggu_approval',
        ]);
        LeaveBalanceReservationEvent::create([
            'employee_id' => $fixture['applicant']->id,
            'leave_request_id' => $otherRequest->id,
            'leave_balance_id' => $fixture['balance']->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 2,
            'dedup_key' => "leave_reservation:{$otherRequest->id}:reserved",
            'created_by' => $fixture['actingUser']->id,
        ]);

        $this->assertRejectedWithoutEffect($fixture, $this->executeFixture($fixture), ValidationException::class);
    }

    public function test_rollover_marker_rejects_recording_without_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();
        LeaveBalanceLedger::create([
            'employee_id' => $fixture['applicant']->id,
            'leave_balance_id' => $fixture['balance']->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'amount' => 0,
            'source_year' => 2026,
            'dedup_key' => "{$fixture['applicant']->id}:2027:rollover_applied",
        ]);

        $this->assertRejectedWithoutEffect($fixture, $this->executeFixture($fixture), ValidationException::class);
    }

    public function test_blank_trimmed_reason_is_rejected_without_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();

        $this->assertRejectedWithoutEffect(
            $fixture,
            fn () => $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, " \n\t "),
            ValidationException::class,
        );
    }

    public function test_action_signature_accepts_only_persisted_entities_and_reason(): void
    {
        $method = new ReflectionMethod(RecordDutyPostponementAction::class, 'execute');

        $this->assertSame(
            ['leaveRequest', 'actor', 'actingUser', 'expectedActiveStepId', 'reason'],
            collect($method->getParameters())->map->getName()->all(),
        );
        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame(LeaveRequest::class, $returnType->getName());
    }

    public function test_persisted_request_facts_win_over_stale_input_model(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $fixture['request']->forceFill([
            'employee_id' => Employee::factory()->create()->id,
            'jumlah_hari_kerja' => 99,
            'tanggal_mulai' => '2030-01-01',
            'status' => 'disetujui',
        ]);

        $result = $this->action()->execute(
            $fixture['request'],
            $fixture['actor'],
            $fixture['actingUser'],
            $fixture['activeStep']->id,
            self::REASON,
        );

        $notification = SimpegNotification::query()->where('data->leave_request_id', $result->id)->sole();
        $this->assertSame($fixture['applicant']->id, $result->employee_id);
        $this->assertSame(5, $result->jumlah_hari_kerja);
        $this->assertSame(2026, $notification->data['source_year']);
        $this->assertSame(5, $notification->data['protected_days']);
    }

    public function test_same_actor_retry_is_idempotent(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $first = $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
        $counts = $this->effectCounts($first->id);

        $second = $this->action()->execute($first, $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);

        $this->assertTrue($first->is($second));
        $this->assertSame(LeaveRequest::STATUS_DUTY_POSTPONED, $second->status);
        $this->assertSame($counts, $this->effectCounts($first->id));
        $this->assertSame([1, 1, 1, 1, 1], array_values($counts));
    }

    public function test_terminal_retry_with_different_step_token_is_rejected_without_new_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $terminal = $this->action()->execute(
            $fixture['request'],
            $fixture['actor'],
            $fixture['actingUser'],
            $fixture['activeStep']->id,
            self::REASON,
        );
        $counts = $this->effectCounts($terminal->id);

        try {
            $this->action()->execute(
                $terminal,
                $fixture['actor'],
                $fixture['actingUser'],
                '00000000-0000-4000-8000-000000000001',
                self::REASON,
            );
            $this->fail('Retry terminal dengan token step berbeda wajib ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('active_step_id', $exception->errors());
            $this->assertSame($counts, $this->effectCounts($terminal->id));
        }
    }

    public function test_same_actor_retry_accepts_legacy_terminal_notification_without_approval_id(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $first = $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
        $counts = $this->effectCounts($first->id);
        $notification = SimpegNotification::query()
            ->where('user_id', $fixture['applicant']->id)
            ->where('type', 'cuti.ditangguhkan_tugas_dinas')
            ->sole();
        $legacyData = $notification->data;
        unset($legacyData['leave_approval_id']);
        $notification->forceFill(['data' => $legacyData])->save();

        $second = $this->action()->execute($first, $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);

        $this->assertTrue($first->is($second));
        $this->assertSame($counts, $this->effectCounts($first->id));
    }

    /**
     * Setelah pengajuan dikembalikan saat rollover lalu diajukan kembali pada tahun target,
     * request yang sama sudah memiliki release rollover lama. Bukti terminal penangguhan dinas
     * harus dibaca dari release miliknya sendiri agar retry tetap idempoten, bukan dituduh korup.
     */
    public function test_retry_stays_idempotent_when_request_already_has_rollover_return_release(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $targetBalance = LeaveBalance::create([
            'employee_id' => $fixture['applicant']->id,
            'tahun' => 2027,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);
        $reservations = app(LeaveBalanceReservationService::class);

        // Rollover mengembalikan pengajuan tahun sumber dan melepas reservasinya.
        $rolloverRelease = $reservations->releaseForRollover($fixture['request'], 2026, 2027);
        $this->assertNotNull($rolloverRelease);
        $fixture['request']->forceFill([
            'status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
            'rollover_source_year' => 2026,
            'rollover_target_year' => 2027,
        ])->save();
        LeaveBalanceLedger::create([
            'employee_id' => $fixture['applicant']->id,
            'leave_balance_id' => $targetBalance->id,
            'tahun' => 2027,
            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
            'amount' => 0,
            'source_year' => 2026,
            'dedup_key' => "{$fixture['applicant']->id}:2027:rollover_applied",
        ]);

        // Pegawai memperbaiki tanggal ke tahun target lalu mengajukan kembali.
        $fixture['request']->forceFill([
            'tanggal_mulai' => '2027-08-02',
            'tanggal_selesai' => '2027-08-06',
            'status' => 'menunggu_approval',
            'rollover_source_year' => null,
            'rollover_target_year' => null,
        ])->save();
        $resubmitted = $fixture['request']->fresh();
        $reservations->adjustForResubmission(
            $resubmitted,
            Carbon::parse('2027-08-02'),
            5,
            $fixture['applicant']->user,
        );

        $terminal = $this->action()->execute(
            $resubmitted,
            $fixture['actor'],
            $fixture['actingUser'],
            $fixture['activeStep']->id,
            self::REASON,
        );

        $this->assertSame(LeaveRequest::STATUS_DUTY_POSTPONED, $terminal->status);
        $this->assertSame(2, LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $terminal->id)
            ->where('event_type', LeaveBalanceReservationEvent::EVENT_RELEASED)
            ->count());

        $before = $this->workflowState($terminal->id, $targetBalance->fresh());
        $retried = $this->action()->execute(
            $terminal,
            $fixture['actor'],
            $fixture['actingUser'],
            $fixture['activeStep']->id,
            self::REASON,
        );

        $this->assertTrue($terminal->is($retried));
        $this->assertSame(LeaveRequest::STATUS_DUTY_POSTPONED, $retried->status);
        $this->assertSame($before, $this->workflowState($terminal->id, $targetBalance->fresh()));
    }

    public function test_disabled_notification_channels_allow_terminal_commit_and_idempotent_retry_without_delivery(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $channelIds = RefNotificationChannel::query()
            ->whereIn('code', ['in_app', 'email'])
            ->pluck('id');
        DB::table('notification_event_channels')
            ->where('event_key', 'cuti.ditangguhkan_tugas_dinas')
            ->whereIn('notification_channel_id', $channelIds)
            ->update(['is_enabled' => false]);

        $terminal = $this->action()->execute(
            $fixture['request'],
            $fixture['actor'],
            $fixture['actingUser'],
            $fixture['activeStep']->id,
            self::REASON,
        );

        $this->assertSame(LeaveRequest::STATUS_DUTY_POSTPONED, $terminal->status);
        $this->assertSame([1, 1, 1, 1, 0], array_values($this->effectCounts($terminal->id)));
        $audit = AuditLog::query()->where('auditable_id', $terminal->id)->where('event', 'DUTY_POSTPONEMENT')->sole();
        $this->assertFalse($audit->new_values['in_app_notification_recorded']);
        Queue::assertNothingPushed();

        $retried = $this->action()->execute(
            $terminal,
            $fixture['actor'],
            $fixture['actingUser'],
            $fixture['activeStep']->id,
            self::REASON,
        );

        $this->assertTrue($terminal->is($retried));
        $this->assertSame([1, 1, 1, 1, 0], array_values($this->effectCounts($terminal->id)));
        Queue::assertNothingPushed();
    }

    public function test_retry_keeps_recorded_notification_when_in_app_policy_is_disabled_after_commit(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $terminal = $this->action()->execute(
            $fixture['request'],
            $fixture['actor'],
            $fixture['actingUser'],
            $fixture['activeStep']->id,
            self::REASON,
        );
        $counts = $this->effectCounts($terminal->id);

        $this->setDutyPostponementChannelPolicy('in_app', false);
        $retried = $this->action()->execute($terminal, $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);

        $this->assertTrue($terminal->is($retried));
        $this->assertSame([1, 1, 1, 1, 1], array_values($counts));
        $this->assertSame($counts, $this->effectCounts($terminal->id));
    }

    public function test_retry_keeps_notification_absent_when_in_app_policy_is_enabled_after_commit(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $this->setDutyPostponementChannelPolicy('in_app', false);
        $terminal = $this->action()->execute(
            $fixture['request'],
            $fixture['actor'],
            $fixture['actingUser'],
            $fixture['activeStep']->id,
            self::REASON,
        );
        $counts = $this->effectCounts($terminal->id);

        $this->setDutyPostponementChannelPolicy('in_app', true);
        $retried = $this->action()->execute($terminal, $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);

        $this->assertTrue($terminal->is($retried));
        $this->assertSame([1, 1, 1, 1, 0], array_values($counts));
        $this->assertSame($counts, $this->effectCounts($terminal->id));
    }

    public function test_terminal_duty_postponement_cannot_be_processed_by_any_generic_decision(): void
    {
        foreach (['approve', 'postpone', 'requestChanges', 'decline'] as $decision) {
            $fixture = $this->makeWorkflowFixture();
            $terminal = $this->action()->execute(
                $fixture['request'],
                $fixture['actor'],
                $fixture['actingUser'],
                $fixture['activeStep']->id,
                self::REASON,
            );
            $before = $this->workflowState($terminal->id, $fixture['balance']);

            try {
                $service = app(LeaveApprovalService::class);
                match ($decision) {
                    'approve' => $service->approve($terminal, $fixture['actor'], $fixture['activeStep']->id, null, $fixture['actingUser']),
                    'postpone' => $service->postpone($terminal, $fixture['actor'], $fixture['activeStep']->id, 'Keputusan lanjutan tidak sah.'),
                    'requestChanges' => $service->requestChanges($terminal, $fixture['actor'], $fixture['activeStep']->id, 'Keputusan lanjutan tidak sah.'),
                    'decline' => $service->decline($terminal, $fixture['actor'], $fixture['activeStep']->id, 'Keputusan lanjutan tidak sah.'),
                };
                $this->fail("Keputusan {$decision} pada workflow terminal wajib ditolak.");
            } catch (ValidationException) {
                $this->assertSame($before, $this->workflowState($terminal->id, $fixture['balance']->fresh()));
            }
        }
    }

    public function test_different_actor_retry_is_rejected_without_new_effect(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $terminal = $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
        $counts = $this->effectCounts($terminal->id);
        $differentActor = Employee::factory()->create();
        $differentUser = User::factory()->kepalaBagian()->create(['employee_id' => $differentActor->id]);

        try {
            $this->action()->execute($terminal, $differentActor, $differentUser, $fixture['activeStep']->id, self::REASON);
            $this->fail('Retry oleh aktor berbeda wajib ditolak.');
        } catch (AuthorizationException) {
            $this->assertSame($counts, $this->effectCounts($terminal->id));
        }
    }

    public function test_terminal_retry_fails_closed_when_any_persisted_effect_is_missing(): void
    {
        // Baris audit tidak ikut diuji hilang karena basis data menolak penghapusannya. Bentuk
        // kerusakan yang masih mungkin pada tabel append-only adalah baris berlebih, dan itu
        // diuji terpisah pada test duplikasi di bawah.
        foreach (['ledger', 'release', 'approval', 'notification'] as $missing) {
            $fixture = $this->makeWorkflowFixture();
            $terminal = $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
            $this->deleteTerminalEffect($terminal->id, $missing);
            $counts = $this->effectCounts($terminal->id);

            try {
                $this->action()->execute($terminal, $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
                $this->fail("Retry terminal dengan efek {$missing} hilang wajib ditolak.");
            } catch (ValidationException) {
                $this->assertSame($counts, $this->effectCounts($terminal->id));
            }
        }
    }

    public function test_terminal_retry_fails_closed_when_any_persisted_effect_is_mismatched(): void
    {
        // Payload audit tidak ikut dirusak karena basis data menolak pembaruannya.
        foreach (['ledger', 'release', 'approval', 'notification'] as $mismatched) {
            $fixture = $this->makeWorkflowFixture();
            $terminal = $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
            $this->corruptTerminalEffect($terminal->id, $mismatched);
            $counts = $this->effectCounts($terminal->id);

            try {
                $this->action()->execute($terminal, $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
                $this->fail("Retry terminal dengan efek {$mismatched} tidak cocok wajib ditolak.");
            } catch (ValidationException) {
                $this->assertSame($counts, $this->effectCounts($terminal->id));
            }
        }
    }

    public function test_terminal_retry_fails_closed_when_audit_row_is_duplicated(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $terminal = $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
        $asli = AuditLog::query()
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $terminal->id)
            ->where('event', 'DUTY_POSTPONEMENT')
            ->sole();

        // Audit hanya dapat bertambah, tidak dapat diubah maupun dihapus. Karena itu kontrak
        // terminal harus menolak keadaan dengan lebih dari satu baris audit penangguhan.
        AuditLog::query()->create([
            'user_id' => $asli->user_id,
            'user_name' => $asli->user_name,
            'event' => 'DUTY_POSTPONEMENT',
            'auditable_type' => 'LeaveRequest',
            'auditable_id' => $terminal->id,
            'old_values' => $asli->old_values,
            'new_values' => $asli->new_values,
            'ip_address' => $asli->ip_address,
            'user_agent' => $asli->user_agent,
        ]);
        $counts = $this->effectCounts($terminal->id);

        try {
            $this->action()->execute($terminal, $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
            $this->fail('Retry terminal dengan baris audit berlebih wajib ditolak.');
        } catch (ValidationException) {
            $this->assertSame($counts, $this->effectCounts($terminal->id));
        }
    }

    public function test_terminal_retry_fails_closed_when_reason_differs_from_audit_snapshot(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $terminal = $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
        $counts = $this->effectCounts($terminal->id);

        // Alasan yang berbeda dari snapshot audit menandakan permintaan ulang bukan pengulangan
        // keputusan yang sama, sehingga kontrak terminal wajib menolaknya.
        try {
            $this->action()->execute($terminal, $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, 'Alasan yang berbeda dari catatan audit.');
            $this->fail('Retry terminal dengan alasan berbeda wajib ditolak.');
        } catch (ValidationException) {
            $this->assertSame($counts, $this->effectCounts($terminal->id));
        }
    }

    public function test_terminal_retry_fails_closed_when_source_facts_metadata_is_corrupted(): void
    {
        foreach (['source_request_workdays' => 99, 'source_status' => 'disetujui'] as $fact => $corruptedValue) {
            $fixture = $this->makeWorkflowFixture();
            $terminal = $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
            $ledger = LeaveBalanceLedger::query()
                ->where('leave_request_id', $terminal->id)
                ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)
                ->sole();
            $metadata = $ledger->metadata;
            $metadata[$fact] = $corruptedValue;
            $this->mutateLedgerForCorruptionFixture(
                fn () => DB::table('leave_balance_ledger')
                    ->where('id', $ledger->id)
                    ->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]),
            );
            $counts = $this->effectCounts($terminal->id);

            try {
                $this->action()->execute($terminal, $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
                $this->fail("Retry terminal dengan metadata {$fact} rusak wajib ditolak.");
            } catch (ValidationException) {
                $this->assertSame($counts, $this->effectCounts($terminal->id));
            }
        }
    }

    public function test_terminal_retry_fails_closed_when_notification_payload_is_corrupted(): void
    {
        foreach (['body', 'data'] as $field) {
            $fixture = $this->makeWorkflowFixture();
            $terminal = $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
            $notification = SimpegNotification::query()
                ->where('data->leave_request_id', $terminal->id)
                ->where('type', 'cuti.ditangguhkan_tugas_dinas')
                ->sole();
            $corruptedValue = $field === 'body'
                ? 'Isi notifikasi dirusak.'
                : json_encode(['leave_request_id' => $terminal->id, 'source_year' => 1999], JSON_THROW_ON_ERROR);
            DB::table('notifications')->where('id', $notification->id)->update([$field => $corruptedValue]);
            $counts = $this->effectCounts($terminal->id);

            try {
                $this->action()->execute($terminal, $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
                $this->fail("Retry terminal dengan notification {$field} rusak wajib ditolak.");
            } catch (ValidationException) {
                $this->assertSame($counts, $this->effectCounts($terminal->id));
            }
        }
    }

    /**
     * Snapshot notifikasi pada audit sebelumnya diuji dengan merusak payload audit yang sudah
     * tersimpan, dan keadaan itu tidak dapat lagi terjadi karena basis data menolak pembaruan
     * baris audit. Yang diuji di sini adalah keadaan yang masih mungkin terjadi, yaitu
     * penangguhan yang tercatat tanpa notifikasi dalam aplikasi karena kanalnya dimatikan.
     * Permintaan ulang pada keadaan itu sah dan tidak boleh menambah efek apa pun.
     */
    public function test_terminal_retry_stays_idempotent_when_in_app_notification_is_disabled(): void
    {
        $this->setDutyPostponementChannelPolicy('in_app', false);
        $fixture = $this->makeWorkflowFixture();
        $terminal = $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
        $counts = $this->effectCounts($terminal->id);
        $this->assertSame(0, $counts['notification']);

        $diulang = $this->action()->execute($terminal, $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);

        $this->assertSame($terminal->id, $diulang->id);
        $this->assertSame($counts, $this->effectCounts($terminal->id));
    }

    public function test_terminal_retry_fails_closed_when_ledger_source_status_does_not_match_audit_snapshot(): void
    {
        $fixture = $this->makeWorkflowFixture();
        $terminal = $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
        $ledger = LeaveBalanceLedger::query()
            ->where('leave_request_id', $terminal->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)
            ->sole();
        // Status pra-terminal pada ledger harus sama dengan snapshot audit. Ketidakcocokan diuji
        // dari sisi ledger karena baris audit tidak dapat diubah lagi setelah tersimpan.
        $metadata = $ledger->metadata;
        $metadata['source_status'] = 'disetujui';
        $this->mutateLedgerForCorruptionFixture(
            fn () => DB::table('leave_balance_ledger')->where('id', $ledger->id)->update([
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]),
        );
        $counts = $this->effectCounts($terminal->id);

        try {
            $this->action()->execute($terminal, $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
            $this->fail('Retry terminal dengan status pra-terminal non-actionable wajib ditolak.');
        } catch (ValidationException) {
            $this->assertSame($counts, $this->effectCounts($terminal->id));
        }
    }

    public function test_postgresql_audit_constraint_failure_rolls_back_all_terminal_effects(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Constraint PostgreSQL hanya dapat diuji pada database PostgreSQL.');
        }
        $fixture = $this->makeWorkflowFixture();
        $before = $this->workflowState($fixture['request']->id, $fixture['balance']);
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_duty_postponement_audit_failure CHECK (event <> 'DUTY_POSTPONEMENT')");

        try {
            $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
            $this->fail('Constraint audit PostgreSQL seharusnya menggagalkan transaksi.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('test_duty_postponement_audit_failure', $exception->getMessage());
            $this->assertSame($before, $this->workflowState($fixture['request']->id, $fixture['balance']->fresh()));
        } finally {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS test_duty_postponement_audit_failure');
        }
    }

    public function test_postgresql_notification_constraint_failure_rolls_back_all_terminal_effects(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Constraint PostgreSQL hanya dapat diuji pada database PostgreSQL.');
        }
        $fixture = $this->makeWorkflowFixture();
        $before = $this->workflowState($fixture['request']->id, $fixture['balance']);
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT test_duty_postponement_notification_failure CHECK (type <> 'cuti.ditangguhkan_tugas_dinas')");

        try {
            $this->action()->execute($fixture['request'], $fixture['actor'], $fixture['actingUser'], $fixture['activeStep']->id, self::REASON);
            $this->fail('Constraint notifikasi PostgreSQL seharusnya menggagalkan transaksi.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('test_duty_postponement_notification_failure', $exception->getMessage());
            $this->assertSame($before, $this->workflowState($fixture['request']->id, $fixture['balance']->fresh()));
        } finally {
            DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS test_duty_postponement_notification_failure');
        }
    }

    private function action(): RecordDutyPostponementAction
    {
        return app(RecordDutyPostponementAction::class);
    }

    /** @param array<string, mixed> $fixture */
    private function executeFixture(array $fixture): callable
    {
        return fn () => $this->action()->execute(
            $fixture['request'],
            $fixture['actor'],
            $fixture['actingUser'],
            $fixture['activeStep']->id,
            self::REASON,
        );
    }

    /**
     * Setiap guard harus mempertahankan seluruh state domain, bukan hanya status request.
     *
     * @param  array<string, mixed>  $fixture
     * @param  class-string<Throwable>  $exceptionClass
     */
    private function assertRejectedWithoutEffect(array $fixture, callable $operation, string $exceptionClass): void
    {
        $before = $this->workflowState($fixture['request']->id, $fixture['balance']);

        try {
            $operation();
            $this->fail("Operasi seharusnya melempar {$exceptionClass}.");
        } catch (Throwable $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
            $this->assertSame($before, $this->workflowState($fixture['request']->id, $fixture['balance']->fresh()));
        }
    }

    /** @return array<string, mixed> */
    private function workflowState(string $requestId, LeaveBalance $balance): array
    {
        return $this->normalizeDatabaseValue([
            'request' => DB::table('leave_requests')->where('id', $requestId)->first(),
            'steps' => DB::table('leave_request_steps')->where('leave_request_id', $requestId)->orderBy('step_order')->get()->all(),
            'ledger' => DB::table('leave_balance_ledger')->where('leave_request_id', $requestId)->orderBy('id')->get()->all(),
            'reservations' => DB::table('leave_balance_reservation_events')->where('leave_request_id', $requestId)->orderBy('id')->get()->all(),
            'approvals' => DB::table('leave_approvals')->where('leave_request_id', $requestId)->orderBy('id')->get()->all(),
            'audits' => DB::table('audit_logs')->where('auditable_type', 'LeaveRequest')->where('auditable_id', $requestId)->orderBy('id')->get()->all(),
            'notifications' => DB::table('notifications')->where('data->leave_request_id', $requestId)->orderBy('id')->get()->all(),
            'proofs' => DB::table('leave_proofs')->where('leave_request_id', $requestId)->orderBy('id')->get()->all(),
            'balance' => $this->balanceSummary($balance),
        ]);
    }

    /** @return array<string, mixed> */
    private function normalizeDatabaseValue(array $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, int> */
    private function effectCounts(string $requestId): array
    {
        return [
            'ledger' => LeaveBalanceLedger::query()->where('leave_request_id', $requestId)->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)->count(),
            'release' => LeaveBalanceReservationEvent::query()->where('leave_request_id', $requestId)->where('event_type', LeaveBalanceReservationEvent::EVENT_RELEASED)->count(),
            'approval' => LeaveApproval::query()->where('leave_request_id', $requestId)->where('action', LeaveApproval::ACTION_DUTY_POSTPONEMENT)->count(),
            'audit' => AuditLog::query()->where('auditable_type', 'LeaveRequest')->where('auditable_id', $requestId)->where('event', 'DUTY_POSTPONEMENT')->count(),
            'notification' => SimpegNotification::query()->where('data->leave_request_id', $requestId)->where('type', 'cuti.ditangguhkan_tugas_dinas')->count(),
        ];
    }

    private function deleteTerminalEffect(string $requestId, string $effect): void
    {
        match ($effect) {
            'ledger' => $this->mutateLedgerForCorruptionFixture(
                fn () => DB::table('leave_balance_ledger')->where('leave_request_id', $requestId)->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)->delete(),
            ),
            'release' => DB::table('leave_balance_reservation_events')->where('leave_request_id', $requestId)->where('event_type', LeaveBalanceReservationEvent::EVENT_RELEASED)->delete(),
            'approval' => DB::table('leave_approvals')->where('leave_request_id', $requestId)->where('action', LeaveApproval::ACTION_DUTY_POSTPONEMENT)->delete(),
            'notification' => DB::table('notifications')->where('data->leave_request_id', $requestId)->where('type', 'cuti.ditangguhkan_tugas_dinas')->delete(),
        };
    }

    private function corruptTerminalEffect(string $requestId, string $effect): void
    {
        match ($effect) {
            'ledger' => $this->mutateLedgerForCorruptionFixture(
                fn () => DB::table('leave_balance_ledger')->where('leave_request_id', $requestId)->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)->update(['reason' => 'Alasan ledger dirusak.']),
            ),
            'release' => DB::table('leave_balance_reservation_events')->where('leave_request_id', $requestId)->where('event_type', LeaveBalanceReservationEvent::EVENT_RELEASED)->update(['created_by' => User::factory()->create()->id]),
            'approval' => DB::table('leave_approvals')->where('leave_request_id', $requestId)->where('action', LeaveApproval::ACTION_DUTY_POSTPONEMENT)->update(['komentar' => 'Komentar history dirusak.']),
            'notification' => DB::table('notifications')->where('data->leave_request_id', $requestId)->where('type', 'cuti.ditangguhkan_tugas_dinas')->update(['title' => 'Judul dirusak']),
        };
    }

    private function mutateLedgerForCorruptionFixture(callable $mutation): void
    {
        // Regression ini membutuhkan state korup buatan; guard ledger tetap aktif di luar mutation fixture sempit ini.
        DB::statement('ALTER TABLE leave_balance_ledger DISABLE TRIGGER leave_balance_ledger_no_update_delete');

        try {
            $mutation();
        } finally {
            DB::statement('ALTER TABLE leave_balance_ledger ENABLE TRIGGER leave_balance_ledger_no_update_delete');
        }
    }

    private function setDutyPostponementChannelPolicy(string $channelCode, bool $isEnabled): void
    {
        $channelId = RefNotificationChannel::query()->where('code', $channelCode)->value('id');

        DB::table('notification_event_channels')
            ->where('event_key', 'cuti.ditangguhkan_tugas_dinas')
            ->where('notification_channel_id', $channelId)
            ->update(['is_enabled' => $isEnabled]);
    }

    /**
     * Membentuk snapshot workflow dan reservasi penuh agar test menguji orkestrasi nyata, bukan state buatan parsial.
     *
     * @return array<string, mixed>
     */
    private function makeWorkflowFixture(): array
    {
        $applicant = Employee::factory()->create();
        Appointment::create([
            'employee_id' => $applicant->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
        ]);
        User::factory()->pegawai()->create(['employee_id' => $applicant->id]);
        $actor = Employee::factory()->create();
        $actingUser = User::factory()->kepalaBagian()->create(['employee_id' => $actor->id]);
        $laterApprover = Employee::factory()->create();
        $annualType = RefJenisCuti::firstOrCreate(
            ['code' => 'tahunan'],
            [
                'nama' => 'Cuti Tahunan',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ],
        );
        $balance = LeaveBalance::create([
            'employee_id' => $applicant->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);
        $request = LeaveRequest::create([
            'employee_id' => $applicant->id,
            'jenis_cuti_id' => $annualType->id,
            'tanggal_mulai' => '2026-08-03',
            'tanggal_selesai' => '2026-08-07',
            'jumlah_hari_kerja' => 5,
            'alasan' => 'Cuti tahunan keluarga.',
            'status' => 'menunggu_approval',
        ]);
        $activeStep = LeaveRequestStep::create([
            'leave_request_id' => $request->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $actor->id,
            'status' => 'active',
            'is_final' => false,
        ]);
        $laterStep = LeaveRequestStep::create([
            'leave_request_id' => $request->id,
            'step_order' => 2,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => $laterApprover->id,
            'status' => 'pending',
            'is_final' => true,
        ]);
        LeaveBalanceReservationEvent::create([
            'employee_id' => $applicant->id,
            'leave_request_id' => $request->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 5,
            'reason' => 'Hak cuti tahunan dialokasikan untuk pengajuan aktif.',
            'dedup_key' => "leave_reservation:{$request->id}:reserved",
            'metadata' => ['requested_days' => 5],
            'created_by' => $applicant->user->id,
            'occurred_at' => Carbon::parse('2026-08-01 08:00:00'),
        ]);

        return compact('applicant', 'actor', 'actingUser', 'balance', 'request', 'activeStep', 'laterStep');
    }

    /** @return array<string, int> */
    private function balanceSummary(LeaveBalance $balance): array
    {
        return collect($balance->only([
            'jatah_awal',
            'carry_over',
            'terpakai',
            'sisa',
            'sisa_n2',
            'sisa_n1',
            'sisa_tahun_berjalan',
            'terpakai_tahun_berjalan',
            'hangus',
        ]))->map(fn (mixed $value): int => (int) $value)->all();
    }
}
