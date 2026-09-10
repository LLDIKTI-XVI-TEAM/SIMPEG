<?php

namespace Tests\Feature;

use App\Actions\Cuti\DecideLeaveCancellationAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\Role;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\LeaveApprovalService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveCancellationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_owner_request_creates_hold_without_changing_steps_or_reservation_and_notifies_only_authorized_admin(): void
    {
        Queue::fake();
        $fixture = $this->makeLeaveFixture();
        $leave = $fixture['leave'];
        $stepsBefore = $leave->steps()->orderBy('step_order')->get(['id', 'status'])->map->toArray()->all();
        $reservationBefore = $this->reservationAmount($leave);

        $this->actingAs($fixture['ownerUser'])
            ->get(route('cuti.show', $leave))
            ->assertOk()
            ->assertSee('id="cancellation-reason"', false)
            ->assertSee('aria-describedby="cancellation-reason-help"', false)
            ->assertSee('x-data="{ submitting: false }"', false)
            ->assertSee('@submit="if (submitting)', false)
            ->assertSee('x-bind:disabled="submitting"', false)
            ->assertSee('x-bind:aria-busy="submitting.toString()"', false)
            ->assertDontSee('value="PERUBAHAN"', false)
            ->assertDontSee('Minta Perubahan');

        $this->actingAs($fixture['ownerUser'])
            ->post("/dashboard/cuti/{$leave->id}/pembatalan", ['reason' => '  Ada perubahan kebutuhan keluarga.  '])
            ->assertRedirect(route('cuti.show', $leave));

        $leave->refresh();
        $cancellation = LeaveCancellationRequest::query()->sole();

        $this->assertSame(LeaveRequest::STATUS_CANCELLATION_PENDING, $leave->status);
        $this->assertSame(LeaveCancellationRequest::STATUS_PENDING, $cancellation->status);
        $this->assertSame('menunggu_approval', $cancellation->resume_status);
        $this->assertSame('Ada perubahan kebutuhan keluarga.', $cancellation->reason);
        $this->assertSame($stepsBefore, $leave->steps()->orderBy('step_order')->get(['id', 'status'])->map->toArray()->all());
        $this->assertSame($reservationBefore, $this->reservationAmount($leave));

        $audit = AuditLog::query()->where('auditable_id', $cancellation->id)->sole();
        $this->assertTrue($audit->new_values['reason_recorded']);
        $this->assertStringNotContainsString($cancellation->reason, json_encode($audit->new_values, JSON_THROW_ON_ERROR));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $fixture['adminEmployee']->id,
            'type' => 'cuti.pembatalan_diajukan',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $fixture['otherEmployee']->id,
            'type' => 'cuti.pembatalan_diajukan',
        ]);
        $notification = SimpegNotification::query()->where('type', 'cuti.pembatalan_diajukan')->sole();
        $this->assertSame(route('cuti.cancellations.index', [], false), $notification->data['url']);
        $this->assertStringNotContainsString($cancellation->reason, $notification->body);
        $this->assertStringNotContainsString($cancellation->reason, json_encode($notification->data, JSON_THROW_ON_ERROR));

        $this->actingAs($fixture['ownerUser'])
            ->get(route('cuti.show', $leave))
            ->assertOk()
            ->assertSee('Menunggu Keputusan Pembatalan', false)
            ->assertSee('Proses persetujuan pengajuan ditahan sampai keputusan Admin Kepegawaian.')
            ->assertDontSee('animate-pulse', false)
            ->assertSee($cancellation->reason, false);

        $this->actingAs($fixture['approverUser'])
            ->get(route('cuti.show', $leave))
            ->assertOk()
            ->assertSee('Menunggu Keputusan Pembatalan', false)
            ->assertDontSee($cancellation->reason, false);
    }

    public function test_owner_sees_pending_cancellation_when_creation_timestamps_match(): void
    {
        $fixture = $this->makeLeaveFixture();
        $leave = $fixture['leave'];
        $createdAt = now()->startOfSecond();
        $rejected = $this->createPendingCancellation($leave, $fixture['ownerUser']);
        $rejected->forceFill([
            'id' => 'ffffffff-ffff-4fff-bfff-ffffffffffff',
            'status' => LeaveCancellationRequest::STATUS_REJECTED,
            'reason' => 'Permohonan lama yang sudah ditolak.',
            'decided_at' => $createdAt,
            'created_at' => $createdAt,
        ])->save();
        $pending = $this->createPendingCancellation($leave, $fixture['ownerUser']);
        $pending->forceFill([
            'id' => '00000000-0000-4000-8000-000000000001',
            'reason' => 'Permohonan aktif yang menunggu keputusan.',
            'created_at' => $createdAt,
        ])->save();

        $this->actingAs($fixture['ownerUser'])
            ->get(route('cuti.show', $leave))
            ->assertOk()
            ->assertViewHas('latestCancellation', fn (LeaveCancellationRequest $item): bool => $item->is($pending))
            ->assertSee($pending->reason)
            ->assertDontSee($rejected->reason);
    }

    public function test_cancellation_rejects_non_owner_final_request_and_duplicate_pending_request_without_mutation(): void
    {
        $fixture = $this->makeLeaveFixture();
        $leave = $fixture['leave'];

        $this->actingAs($fixture['otherUser'])
            ->postJson("/dashboard/cuti/{$leave->id}/pembatalan", ['reason' => 'Bukan milik saya.'])
            ->assertForbidden();
        $this->assertDatabaseCount('leave_cancellation_requests', 0);

        $leave->forceFill(['status' => 'disetujui'])->save();
        $this->actingAs($fixture['ownerUser'])
            ->postJson("/dashboard/cuti/{$leave->id}/pembatalan", ['reason' => 'Sudah final.'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('leave_cancellation_requests', 0);

        $leave->forceFill(['status' => 'menunggu_approval'])->save();
        LeaveCancellationRequest::create([
            'leave_request_id' => $leave->id,
            'requested_by' => $fixture['ownerUser']->id,
            'reason' => 'Permohonan awal.',
            'status' => LeaveCancellationRequest::STATUS_PENDING,
            'resume_status' => 'menunggu_approval',
        ]);
        $leave->forceFill(['status' => LeaveRequest::STATUS_CANCELLATION_PENDING])->save();

        $this->actingAs($fixture['ownerUser'])
            ->postJson("/dashboard/cuti/{$leave->id}/pembatalan", ['reason' => 'Permohonan kedua.'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('leave_cancellation_requests', 1);
        $this->assertSame(5, $this->reservationAmount($leave));

        $this->actingAs($fixture['ownerUser'])
            ->from(route('cuti.show', $leave))
            ->followingRedirects()
            ->post("/dashboard/cuti/{$leave->id}/pembatalan", ['reason' => 'Form lama.'])
            ->assertOk()
            ->assertSee('Pengajuan cuti ini tidak dapat diminta pembatalannya.')
            ->assertDontSee('id="cancellation-reason"', false);
    }

    public function test_cancellation_validates_reason_and_malformed_uuid_route_is_not_found(): void
    {
        $fixture = $this->makeLeaveFixture();

        $this->actingAs($fixture['ownerUser'])
            ->from(route('cuti.show', $fixture['leave']))
            ->followingRedirects()
            ->post("/dashboard/cuti/{$fixture['leave']->id}/pembatalan", ['reason' => '   '])
            ->assertOk()
            ->assertSee('Kolom alasan pembatalan wajib diisi.')
            ->assertSee('id="cancellation-reason-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="cancellation-reason-help cancellation-reason-error"', false);
        $this->assertDatabaseCount('leave_cancellation_requests', 0);

        $this->actingAs($fixture['ownerUser'])
            ->post('/dashboard/cuti/bukan-uuid/pembatalan', ['reason' => 'Alasan valid.'])
            ->assertNotFound();
    }

    public function test_approval_is_fail_closed_while_cancellation_hold_is_pending(): void
    {
        $fixture = $this->makeLeaveFixture();
        $leave = $fixture['leave'];
        $leave->forceFill(['status' => LeaveRequest::STATUS_CANCELLATION_PENDING])->save();

        try {
            app(LeaveApprovalService::class)->approve(
                $leave,
                $fixture['approverEmployee'],
                $fixture['activeStep']->id,
                (int) $leave->revision_version,
                null,
                $fixture['approverUser'],
            );
            $this->fail('Approver harus ditolak ketika pengajuan sedang menunggu pembatalan.');
        } catch (ValidationException $exception) {
            $this->assertSame('Pengajuan cuti ini belum dapat diproses oleh approver.', $exception->errors()['status'][0] ?? null);
        }

        $this->assertSame('active', $fixture['activeStep']->fresh()->status);
        $this->assertSame(5, $this->reservationAmount($leave));
    }

    public function test_admin_cancellation_queue_is_authorized_paginated_filtered_and_rejects_malformed_uuid(): void
    {
        $fixture = $this->makeLeaveFixture();
        $adminUser = $fixture['adminEmployee']->user;
        $oldestCancellation = $this->createPendingCancellation($fixture['leave'], $fixture['ownerUser']);
        $oldestCancellation->forceFill(['created_at' => now()->subMinute()])->save();

        foreach (range(1, 10) as $index) {
            $owner = Employee::factory()->create();
            $ownerUser = User::factory()->pegawai()->create(['employee_id' => $owner->id]);
            $leave = LeaveRequest::create([
                'employee_id' => $owner->id,
                'jenis_cuti_id' => $fixture['leave']->jenis_cuti_id,
                'tanggal_mulai' => '2026-10-01',
                'tanggal_selesai' => '2026-10-03',
                'jumlah_hari_kerja' => 3,
                'alasan' => "Antrean pembatalan {$index}.",
                'status' => LeaveRequest::STATUS_CANCELLATION_PENDING,
            ]);
            $this->createPendingCancellation($leave, $ownerUser);
        }

        $approved = $this->createPendingCancellation(
            LeaveRequest::create([
                'employee_id' => Employee::factory()->create()->id,
                'jenis_cuti_id' => $fixture['leave']->jenis_cuti_id,
                'tanggal_mulai' => '2026-10-06',
                'tanggal_selesai' => '2026-10-08',
                'jumlah_hari_kerja' => 3,
                'alasan' => 'Pembatalan yang sudah diputus.',
                'status' => LeaveRequest::STATUS_CANCELLATION_PENDING,
            ]),
            User::factory()->pegawai()->create(),
        );
        $approved->forceFill([
            'status' => LeaveCancellationRequest::STATUS_APPROVED,
            'decided_by' => $adminUser->id,
            'decided_at' => now(),
        ])->save();

        $this->actingAs($fixture['otherUser'])
            ->get(route('cuti.cancellations.index'))
            ->assertForbidden();

        $defaultResponse = $this->actingAs($adminUser)
            ->get(route('cuti.cancellations.index'));

        $defaultResponse
            ->assertOk()
            ->assertSee($oldestCancellation->reason, false)
            ->assertSee('01 Sep 2026', false)
            ->assertSee('05 Sep 2026', false)
            ->assertSee('Daftar kartu pembatalan cuti', false)
            ->assertSee('Setujui Pembatalan')
            ->assertSee('Tolak Pembatalan')
            ->assertSee('Konfirmasi Keputusan Pembatalan')
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-describedby="cancellation-confirmation-description"', false)
            ->assertSee('data-modal-initial-focus="true"', false)
            ->assertSee('data-cancellation-action="'.route('cuti.cancellations.decide', $oldestCancellation).'"', false)
            ->assertSee('x-bind:value="confirmation.decision"', false)
            ->assertSee('if (submitting) { $event.preventDefault() }', false)
            ->assertDontSee('window.confirm', false)
            ->assertSee('x-bind:disabled="submitting"', false)
            ->assertSee('grid grid-cols-1 gap-3 sm:grid-cols-2', false)
            ->assertSee('href="'.route('cuti.show', $fixture['leave']).'"', false)
            ->assertViewHas('cancellations', function ($items) use ($oldestCancellation): bool {
                return $items->total() === 11
                    && $items->first()?->id === $oldestCancellation->id
                    && $items->every(fn (LeaveCancellationRequest $item): bool => $item->status === LeaveCancellationRequest::STATUS_PENDING);
            });

        $response = $this->actingAs($adminUser)
            ->get(route('cuti.cancellations.index', ['status' => 'pending', 'per_page' => 10]));

        $response->assertOk()->assertViewHas('cancellations');
        $cancellations = $response->viewData('cancellations');
        $this->assertSame(11, $cancellations->total());
        $this->assertCount(10, $cancellations);
        $this->assertTrue($cancellations->every(
            fn (LeaveCancellationRequest $cancellation): bool => $cancellation->status === LeaveCancellationRequest::STATUS_PENDING,
        ));

        $this->actingAs($adminUser)
            ->get(route('cuti.cancellations.index', ['status' => 'approved']))
            ->assertOk()
            ->assertViewHas('cancellations', fn ($items): bool => $items->total() === 1);

        $this->actingAs($adminUser)
            ->get(route('cuti.cancellations.index', ['status' => 'all']))
            ->assertOk()
            ->assertViewHas('cancellations', fn ($items): bool => $items->total() === 12);

        $this->actingAs($adminUser)
            ->patch('/cuti/pembatalan/bukan-uuid/keputusan', ['decision' => 'DISETUJUI'])
            ->assertNotFound();
    }

    public function test_admin_rejection_restores_exact_resume_status_without_touching_steps_or_reservation(): void
    {
        $fixture = $this->makeLeaveFixture();
        $leave = $fixture['leave'];
        $leave->forceFill(['status' => 'ditangguhkan'])->save();
        $cancellation = $this->createPendingCancellation($leave, $fixture['ownerUser'], 'ditangguhkan');
        $stepsBefore = $leave->steps()->orderBy('step_order')->get(['id', 'status'])->map->toArray()->all();
        $reservationBefore = $this->reservationAmount($leave);

        $this->actingAs($fixture['adminEmployee']->user)
            ->patch(route('cuti.cancellations.decide', $cancellation), ['decision' => 'DITOLAK'])
            ->assertRedirect(route('cuti.cancellations.index'));

        $this->assertSame(LeaveCancellationRequest::STATUS_REJECTED, $cancellation->fresh()->status);
        $this->assertSame('ditangguhkan', $leave->fresh()->status);
        $this->assertSame($stepsBefore, $leave->steps()->orderBy('step_order')->get(['id', 'status'])->map->toArray()->all());
        $this->assertSame($reservationBefore, $this->reservationAmount($leave));
        $this->assertDatabaseMissing('leave_balance_reservation_events', [
            'leave_request_id' => $leave->id,
            'dedup_key' => "leave_reservation:{$leave->id}:released:approved_cancellation:{$cancellation->id}:2026",
        ]);

        $this->actingAs($fixture['ownerUser'])
            ->get(route('cuti.show', $leave))
            ->assertOk()
            ->assertSee('Pembatalan ditolak')
            ->assertSee($cancellation->reason, false);
    }

    public function test_decision_action_rechecks_permission_before_mutation(): void
    {
        $fixture = $this->makeLeaveFixture();
        $cancellation = $this->createPendingCancellation($fixture['leave'], $fixture['ownerUser']);
        $request = Request::create('/cuti/pembatalan/'.$cancellation->id.'/keputusan', 'PATCH');
        $request->setUserResolver(fn (): User => $fixture['otherUser']);

        try {
            app(DecideLeaveCancellationAction::class)->execute(
                $cancellation,
                $fixture['otherUser'],
                'DISETUJUI',
                $request,
            );
            $this->fail('Action keputusan pembatalan harus memeriksa ulang permission pembatalan.');
        } catch (AuthorizationException) {
            $this->assertSame(LeaveCancellationRequest::STATUS_PENDING, $cancellation->fresh()->status);
            $this->assertSame(LeaveRequest::STATUS_CANCELLATION_PENDING, $fixture['leave']->fresh()->status);
            $this->assertSame(5, $this->reservationAmount($fixture['leave']));
        }
    }

    public function test_role_lain_dapat_mengelola_pembatalan_saat_permission_diberikan(): void
    {
        $fixture = $this->makeLeaveFixture();
        $cancellation = $this->createPendingCancellation($fixture['leave'], $fixture['ownerUser']);
        $pimpinan = User::factory()->pimpinan()->create();
        $permission = Permission::query()
            ->where('name', 'cuti.cancellation.manage')
            ->firstOrFail();
        Role::query()
            ->where('name', 'pimpinan')
            ->firstOrFail()
            ->permissions()
            ->syncWithoutDetaching([$permission->id]);

        $this->actingAs($pimpinan)
            ->get(route('cuti.cancellations.index'))
            ->assertOk();

        $this->actingAs($pimpinan)
            ->patch(route('cuti.cancellations.decide', $cancellation), ['decision' => 'DITOLAK'])
            ->assertRedirect(route('cuti.cancellations.index'));

        $this->assertSame(LeaveCancellationRequest::STATUS_REJECTED, $cancellation->fresh()->status);
    }

    public function test_admin_approval_cancels_main_request_skips_open_steps_and_releases_reservation_once(): void
    {
        $fixture = $this->makeLeaveFixture();
        $leave = $fixture['leave'];
        $pendingStep = $leave->steps()->create([
            'step_order' => 2,
            'step_type' => 'pybmc',
            'role_label' => 'PYBMC',
            'approver_employee_id' => Employee::factory()->create()->id,
            'status' => 'pending',
            'is_final' => true,
        ]);
        $cancellation = $this->createPendingCancellation($leave, $fixture['ownerUser']);

        $this->actingAs($fixture['adminEmployee']->user)
            ->patch(route('cuti.cancellations.decide', $cancellation), ['decision' => 'DISETUJUI'])
            ->assertRedirect(route('cuti.cancellations.index'));

        $this->assertSame(LeaveCancellationRequest::STATUS_APPROVED, $cancellation->fresh()->status);
        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $leave->fresh()->status);
        $this->assertSame('skipped', $fixture['activeStep']->fresh()->status);
        $this->assertSame(LeaveRequestStep::SKIPPED_REQUEST_CANCELLED, $fixture['activeStep']->fresh()->skipped_reason);
        $this->assertSame('skipped', $pendingStep->fresh()->status);
        $this->assertSame(LeaveRequestStep::SKIPPED_REQUEST_CANCELLED, $pendingStep->fresh()->skipped_reason);
        $this->assertSame(0, $this->reservationAmount($leave));
        $releaseQuery = LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leave->id)
            ->where('dedup_key', "leave_reservation:{$leave->id}:released:approved_cancellation:{$cancellation->id}:2026");
        $this->assertSame(1, $releaseQuery->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $fixture['ownerEmployee']->id,
            'type' => 'cuti.pembatalan_disetujui',
        ]);

        $decisionAudit = AuditLog::query()
            ->where('auditable_id', $cancellation->id)
            ->where('event', 'LEAVE_CANCELLATION_APPROVED')
            ->sole();
        $this->assertTrue($decisionAudit->new_values['reason_recorded']);
        $this->assertSame(LeaveRequest::STATUS_CANCELLATION_PENDING, $decisionAudit->new_values['leave_request_status_before']);
        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $decisionAudit->new_values['leave_request_status_after']);
        $this->assertStringNotContainsString($cancellation->reason, json_encode($decisionAudit->new_values, JSON_THROW_ON_ERROR));

        $auditCount = AuditLog::query()
            ->where('auditable_id', $cancellation->id)
            ->where('event', 'LEAVE_CANCELLATION_APPROVED')
            ->count();
        $notificationCount = SimpegNotification::query()
            ->where('user_id', $fixture['ownerEmployee']->id)
            ->where('type', 'cuti.pembatalan_disetujui')
            ->count();

        $this->actingAs($fixture['adminEmployee']->user)
            ->patch(route('cuti.cancellations.decide', $cancellation), ['decision' => 'DISETUJUI'])
            ->assertSessionHasErrors('decision');

        $this->assertSame(1, $releaseQuery->count());
        $this->assertSame($auditCount, AuditLog::query()
            ->where('auditable_id', $cancellation->id)
            ->where('event', 'LEAVE_CANCELLATION_APPROVED')
            ->count());
        $this->assertSame($notificationCount, SimpegNotification::query()
            ->where('user_id', $fixture['ownerEmployee']->id)
            ->where('type', 'cuti.pembatalan_disetujui')
            ->count());
    }

    /** @return array{leave: LeaveRequest, ownerEmployee: Employee, ownerUser: User, otherUser: User, otherEmployee: Employee, adminEmployee: Employee, approverEmployee: Employee, approverUser: User, activeStep: LeaveRequestStep} */
    private function makeLeaveFixture(): array
    {
        $owner = Employee::factory()->create();
        $ownerUser = User::factory()->pegawai()->create(['employee_id' => $owner->id]);
        $otherEmployee = Employee::factory()->create();
        $otherUser = User::factory()->pegawai()->create(['employee_id' => $otherEmployee->id]);
        $adminEmployee = Employee::factory()->create(['email' => 'admin-cancellation@example.test']);
        User::factory()->adminKepegawaian()->create(['employee_id' => $adminEmployee->id]);
        $approverEmployee = Employee::factory()->create();
        $approverUser = User::factory()->kepalaBagian()->create(['employee_id' => $approverEmployee->id]);
        $jenis = RefJenisCuti::query()
            ->where('code', 'tahunan')
            ->firstOrCreate([
                'code' => 'tahunan',
            ], [
                'nama' => 'Cuti Tahunan',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ]);
        $balance = LeaveBalance::create([
            'employee_id' => $owner->id,
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
        $leave = LeaveRequest::create([
            'employee_id' => $owner->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-05',
            'jumlah_hari_kerja' => 5,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);
        $activeStep = $leave->steps()->create([
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Atasan Langsung',
            'approver_employee_id' => $approverEmployee->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        LeaveBalanceReservationEvent::create([
            'employee_id' => $owner->id,
            'leave_request_id' => $leave->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 5,
            'reason' => 'Pengajuan cuti tahunan.',
            'dedup_key' => "leave_reservation:{$leave->id}:reserved:2026",
            'occurred_at' => now(),
        ]);

        return compact('leave', 'owner', 'ownerUser', 'otherUser', 'otherEmployee', 'adminEmployee', 'approverEmployee', 'approverUser', 'activeStep') + ['ownerEmployee' => $owner];
    }

    private function createPendingCancellation(LeaveRequest $leave, User $owner, string $resumeStatus = 'menunggu_approval'): LeaveCancellationRequest
    {
        $leave->forceFill(['status' => LeaveRequest::STATUS_CANCELLATION_PENDING])->save();

        return LeaveCancellationRequest::create([
            'leave_request_id' => $leave->id,
            'requested_by' => $owner->id,
            'reason' => 'Permohonan pembatalan untuk pengujian keputusan admin.',
            'status' => LeaveCancellationRequest::STATUS_PENDING,
            'resume_status' => $resumeStatus,
        ]);
    }

    private function reservationAmount(LeaveRequest $leave): int
    {
        return (int) LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leave->id)
            ->sum('amount');
    }
}
