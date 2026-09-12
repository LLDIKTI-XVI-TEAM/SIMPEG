<?php

namespace Tests\Feature;

use App\Actions\Cuti\DecideLeaveCancellationAction;
use App\Actions\Cuti\RequestLeaveCancellationAction;
use App\Jobs\SendSimpegNotificationEmailJob;
use App\Mail\SimpegNotificationMail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\Role;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Notifications\NotificationRecipientResolver;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeaveCancellationNotificationRecipientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RbacSeeder::class, ReferenceSeeder::class]);
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 9, 11)->setTime(10, 0));
    }

    public function test_antrean_pegawai_hanya_memuat_alasan_sendiri_dan_total_dalam_scope(): void
    {
        $actor = $this->actor('pegawai');
        $this->grant('pegawai');
        $own = $this->cancellation($actor);
        $foreign = $this->cancellation($this->actor('pegawai'));

        $this->actingAs($actor)->get(route('cuti.cancellations.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee($own->reason)
            ->assertDontSee($foreign->reason)
            ->assertViewHas('cancellations', fn ($items): bool => $items->total() === 1 && $items->first()->id === $own->id);

        $this->actingAs($actor)->patch(route('cuti.cancellations.decide', $foreign), ['decision' => 'DITOLAK'])
            ->assertForbidden();
        $this->assertSame(LeaveCancellationRequest::STATUS_PENDING, $foreign->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->actingAs($actor)->patch(route('cuti.cancellations.decide', $own), ['decision' => 'DITOLAK'])
            ->assertRedirect(route('cuti.cancellations.index'));
        $this->assertSame(LeaveCancellationRequest::STATUS_REJECTED, $own->fresh()->status);
        $this->actingAs($actor)->get(route('cuti.cancellations.index', ['status' => 'rejected']))
            ->assertOk()->assertSee($own->reason)->assertDontSee($foreign->reason);
    }

    public function test_kepala_bagian_hanya_melihat_dan_memutus_bawahan_efektif(): void
    {
        $actor = $this->actor('kepala_bagian');
        $this->grant('kepala_bagian');
        $currentOwner = $this->actor('pegawai');
        $futureOwner = $this->actor('pegawai');
        $this->assign($currentOwner->employee, $actor, today()->subDay()->toDateString());
        $this->assign($futureOwner->employee, $actor, today()->addDay()->toDateString());
        $current = $this->cancellation($currentOwner);
        $future = $this->cancellation($futureOwner);

        $this->actingAs($actor)->get(route('cuti.cancellations.index'))
            ->assertOk()->assertSee($current->reason)->assertDontSee($future->reason)
            ->assertViewHas('cancellations', fn ($items): bool => $items->total() === 1);

        $request = Request::create('/cuti/pembatalan/'.$future->id.'/keputusan', 'PATCH');
        $request->setUserResolver(fn () => $actor);
        try {
            app(DecideLeaveCancellationAction::class)->execute($future, $actor, 'DITOLAK', $request);
            $this->fail('Action harus menolak pembatalan bawahan yang assignment-nya belum efektif.');
        } catch (AuthorizationException) {
            $this->assertSame(LeaveCancellationRequest::STATUS_PENDING, $future->fresh()->status);
        }

        $this->actingAs($actor)->patch(route('cuti.cancellations.decide', $current), ['decision' => 'DITOLAK'])
            ->assertRedirect();
        $this->assertSame('menunggu_approval', $current->leaveRequest->fresh()->status);
    }

    public function test_keputusan_menolak_actor_lama_setelah_role_asli_berubah_menjadi_pegawai(): void
    {
        $actor = $this->actor('admin_kepegawaian');
        $this->grant('admin_kepegawaian');
        $this->grant('pegawai');
        $cancellation = $this->cancellation($this->actor('pegawai'));
        $auditCount = AuditLog::query()->count();
        $request = Request::create('/cuti/pembatalan/'.$cancellation->id.'/keputusan', 'PATCH');
        $request->setUserResolver(fn () => $actor);

        // Permission tetap aktif; hanya scope identitas tersimpan yang menyempit setelah actor dimuat.
        User::query()->whereKey($actor->id)->update(['role' => 'pegawai']);
        $this->assertSame('admin_kepegawaian', $actor->role);
        $this->assertSame('pegawai', User::query()->whereKey($actor->id)->value('role'));

        try {
            app(DecideLeaveCancellationAction::class)->execute($cancellation, $actor, 'DITOLAK', $request);
            $this->fail('Action harus memeriksa scope identitas terkini sebelum memutus pembatalan pegawai lain.');
        } catch (AuthorizationException) {
            $this->assertSame(LeaveCancellationRequest::STATUS_PENDING, $cancellation->fresh()->status);
            $this->assertNull($cancellation->fresh()->decided_by);
            $this->assertSame(LeaveRequest::STATUS_CANCELLATION_PENDING, $cancellation->leaveRequest->fresh()->status);
            $this->assertDatabaseCount('audit_logs', $auditCount);
        }
    }

    public function test_simulasi_memakai_permission_efektif_dan_scope_identitas_asli_tanpa_bypass_read_all(): void
    {
        $actor = $this->actor('super_admin', ['temporary_role' => 'pegawai']);
        $this->grant('pegawai');
        $cancellation = $this->cancellation($this->actor('pegawai'));

        $this->actingAs($actor)->get(route('cuti.cancellations.index'))
            ->assertOk()->assertSee($cancellation->reason);
        $this->grant('pegawai', false);
        $this->actingAs($actor->refresh())->get(route('cuti.cancellations.index'))->assertForbidden();
        $this->actingAs($actor)->patch(route('cuti.cancellations.decide', $cancellation), ['decision' => 'DITOLAK'])
            ->assertForbidden();
        $this->assertSame(LeaveCancellationRequest::STATUS_PENDING, $cancellation->fresh()->status);
    }

    #[DataProvider('recipientCases')]
    public function test_penerima_mengikuti_permission_binding_scope_dan_simulasi(
        string $role,
        bool $granted,
        string $scope,
        bool $expected,
        ?string $temporaryRole = null,
    ): void {
        $this->grant('admin_kepegawaian', false);
        $actor = $this->actor($role, ['temporary_role' => $temporaryRole]);
        $this->grant($temporaryRole ?? $role, $granted);
        $owner = $scope === 'self' ? $actor : $this->actor('pegawai');
        if (in_array($scope, ['current', 'future'], true)) {
            $this->assign($owner->employee, $actor, $scope === 'current' ? today()->toDateString() : today()->addDay()->toDateString());
        }
        if ($scope === 'inactive') {
            $actor->employee->update(['status_aktif' => 'Nonaktif']);
        } elseif ($scope === 'unmapped') {
            $actor->update(['employee_id' => null]);
        }
        $leave = $this->leave($owner);

        $recipients = collect(app(NotificationRecipientResolver::class)->cancellationDecisionRecipients($leave));

        $this->assertSame($expected, $recipients->contains('id', $actor->employee_id));
        $this->assertSame($recipients->count(), $recipients->unique('id')->count());
    }

    public static function recipientCases(): array
    {
        return [
            'admin revoked' => ['admin_kepegawaian', false, 'foreign', false],
            'super admin no bypass' => ['super_admin', false, 'foreign', false],
            'super admin delegated' => ['super_admin', true, 'foreign', true],
            'pimpinan delegated' => ['pimpinan', true, 'foreign', true],
            'pegawai self' => ['pegawai', true, 'self', true],
            'pegawai foreign' => ['pegawai', true, 'foreign', false],
            'kepala bagian current' => ['kepala_bagian', true, 'current', true],
            'kepala bagian future' => ['kepala_bagian', true, 'future', false],
            'inactive employee' => ['admin_kepegawaian', true, 'inactive', false],
            'missing binding' => ['admin_kepegawaian', true, 'unmapped', false],
            'unknown role' => ['unknown_internal_role', false, 'foreign', false],
            'simulated employee retains global identity scope' => ['super_admin', true, 'foreign', true, 'pegawai'],
            'simulated role revoked' => ['super_admin', false, 'foreign', false, 'pegawai'],
            'invalid simulation cannot grant permission' => ['pegawai', true, 'foreign', false, 'admin_kepegawaian'],
        ];
    }

    public function test_schema_menolak_binding_employee_ambigu_sebelum_notifikasi_dibuat(): void
    {
        $actor = $this->actor('admin_kepegawaian');

        try {
            DB::transaction(fn () => User::factory()->pegawai()->create(['employee_id' => $actor->employee_id]));
            $this->fail('Satu Employee tidak boleh menjadi inbox bersama dua akun.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
            $this->assertStringContainsString('users_employee_id_unique', $exception->getMessage());
        }

        $this->assertSame(1, User::query()->where('employee_id', $actor->employee_id)->count());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_fanout_delegated_tetap_generik_dan_tidak_menggandakan_hold_atau_audit(): void
    {
        $this->grant('admin_kepegawaian', false);
        $this->grant('pimpinan');
        $first = $this->actor('pimpinan');
        $second = $this->actor('pimpinan');
        $owner = $this->actor('pegawai');
        $leave = $this->leave($owner);
        $request = Request::create('/dashboard/cuti/'.$leave->id.'/pembatalan', 'POST');
        $request->setUserResolver(fn () => $owner);
        $reason = 'Alasan privat pembatalan tidak boleh masuk notifikasi.';

        $cancellation = app(RequestLeaveCancellationAction::class)->execute($leave, $owner, $reason, $request);

        $items = SimpegNotification::query()->where('type', 'cuti.pembatalan_diajukan')->get();
        $this->assertEqualsCanonicalizing([$first->employee_id, $second->employee_id], $items->pluck('user_id')->all());
        foreach ($items as $item) {
            $this->assertSame(route('cuti.cancellations.index', [], false), $item->data['url']);
            $this->assertStringNotContainsString($reason, $item->toJson());
        }
        $this->assertDatabaseCount('leave_cancellation_requests', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'LEAVE_CANCELLATION_REQUESTED')->count());
        $this->assertSame(LeaveRequest::STATUS_CANCELLATION_PENDING, $leave->fresh()->status);
        $this->assertSame($reason, $cancellation->reason);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 2);
    }

    #[DataProvider('queuedChanges')]
    public function test_email_antre_memeriksa_ulang_penerima_dan_pending_sebelum_kirim(string $change): void
    {
        Mail::fake();
        $this->grant('admin_kepegawaian', false);
        $this->grant('kepala_bagian');
        $actor = $this->actor('kepala_bagian');
        $owner = $this->actor('pegawai');
        $this->assign($owner->employee, $actor, today()->subDay()->toDateString());
        $leave = $this->leave($owner);
        $request = Request::create('/dashboard/cuti/'.$leave->id.'/pembatalan', 'POST');
        $request->setUserResolver(fn () => $owner);
        $cancellation = app(RequestLeaveCancellationAction::class)->execute($leave, $owner, 'Alasan privat.', $request);
        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);
        $job = $queue->pushed(SendSimpegNotificationEmailJob::class)->sole();

        match ($change) {
            'revoked' => $this->grant('kepala_bagian', false),
            'scope' => $owner->employee->supervisorAssignments()->update(['tanggal_berakhir' => today()->subDay()->toDateString()]),
            'inactive' => $actor->employee->update(['status_aktif' => 'Nonaktif']),
            'unmapped' => $actor->update(['employee_id' => null]),
            'decided' => $cancellation->update(['status' => LeaveCancellationRequest::STATUS_REJECTED, 'decided_by' => $actor->id, 'decided_at' => now()]),
            'parent_state' => $leave->update(['status' => 'menunggu_approval']),
            default => null,
        };
        app()->call([$job, 'handle']);

        if ($change === 'unchanged') {
            Mail::assertSent(SimpegNotificationMail::class, fn ($mail): bool => $mail->hasTo($actor->employee->email_pribadi));
            Mail::assertSentCount(1);
        } else {
            Mail::assertNothingSent();
        }
        $this->assertDatabaseHas('notifications', ['type' => 'cuti.pembatalan_diajukan', 'user_id' => $job->employeeId]);
    }

    public static function queuedChanges(): array
    {
        return [
            'unchanged' => ['unchanged'],
            'permission revoked' => ['revoked'],
            'scope changed' => ['scope'],
            'employee inactive' => ['inactive'],
            'binding removed' => ['unmapped'],
            'cancellation decided' => ['decided'],
            'parent state changed' => ['parent_state'],
        ];
    }

    private function actor(string $role, array $attributes = []): User
    {
        return User::factory()->create(['role' => $role, 'employee_id' => Employee::factory()->create()->id, ...$attributes]);
    }

    private function grant(string $role, bool $granted = true): void
    {
        $record = Role::query()->where('name', $role)->first();
        if ($record === null) {
            return;
        }
        $permission = Permission::query()->where('name', 'cuti.cancellation.manage')->sole();
        if ($granted) {
            $record->permissions()->syncWithoutDetaching([$permission->id]);
        } else {
            $record->permissions()->detach($permission->id);
        }
    }

    private function leave(User $owner): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $owner->employee_id,
            'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'tahunan')->sole()->id,
            'tanggal_mulai' => '2026-10-01',
            'tanggal_selesai' => '2026-10-02',
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Keperluan pengujian cuti.',
            'status' => 'menunggu_approval',
        ]);
    }

    private function cancellation(User $owner): LeaveCancellationRequest
    {
        $leave = $this->leave($owner);
        $leave->update(['status' => LeaveRequest::STATUS_CANCELLATION_PENDING]);

        return LeaveCancellationRequest::create([
            'leave_request_id' => $leave->id,
            'requested_by' => $owner->id,
            'reason' => 'Alasan privat '.$leave->id,
            'status' => LeaveCancellationRequest::STATUS_PENDING,
            'resume_status' => 'menunggu_approval',
        ]);
    }

    private function assign(Employee $employee, User $supervisor, string $start): void
    {
        $employee->supervisorAssignments()->create(['kepala_bagian_id' => $supervisor->employee_id, 'tanggal_mulai' => $start]);
    }
}
