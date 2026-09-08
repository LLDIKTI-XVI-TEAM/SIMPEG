<?php

namespace Tests\Feature;

use App\Actions\Cuti\RecordAdministrativeLeavePostponementAction;
use App\Jobs\SendSimpegNotificationEmailJob;
use App\Mail\SimpegNotificationMail;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Models\Role;
use App\Models\SimpegNotification;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use App\Services\Cuti\LeaveUsageRecordService;
use App\Services\Notifications\NotificationEventCatalog;
use App\Services\NotificationService;
use App\Support\Audit\AuditLogReadPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdministrativeLeavePostponementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'cuti.administrative_postponement.manage';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-06 09:00:00', 'Asia/Makassar'));
        $this->seedReferenceData();
        $this->seedRbac();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_menangguhkan_sekali_dengan_histori_dan_audit_utuh(): void
    {
        [$leave, $admin] = $this->fixture();
        $steps = $leave->steps()->get()->toArray();
        $reservations = $leave->balanceReservationEvents()->get()->toArray();

        $this->actingAs($admin)->post($this->endpoint($leave), ['alasan' => '  Jadwal kegiatan instansi berubah.  '])
            ->assertRedirect(route('cuti.show', $leave));

        $leave->refresh();
        $this->assertSame('ditangguhkan_administratif', $leave->status);
        $this->assertSame($admin->id, $leave->administratively_postponed_by);
        $this->assertSame('Jadwal kegiatan instansi berubah.', $leave->administrative_postponement_reason);
        $this->assertSame($steps, $leave->steps()->get()->toArray());
        $this->assertSame($reservations, $leave->balanceReservationEvents()->get()->toArray());
        $this->assertSame(24, LeaveBalance::query()->where('employee_id', $leave->employee_id)->where('tahun', 2026)->sole()->sisa);
        $audit = AuditLog::query()->where('auditable_type', 'LeaveRequest')->where('auditable_id', $leave->id)->where('new_values->operation', 'administrative_postponement')->sole();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame($leave->administrative_postponement_reason, $audit->new_values['reason']);
        $this->assertStringNotContainsString($leave->administrative_postponement_reason, $leave->toJson());

        $counts = [AuditLog::count(), LeaveBalanceLedger::count()];
        $this->post($this->endpoint($leave), ['alasan' => 'Coba kirim kembali.'])
            ->assertSessionHasErrors(['status' => 'Cuti ini sudah ditangguhkan secara administratif.'], null, 'administrativePostponement');
        $this->assertSame($counts, [AuditLog::count(), LeaveBalanceLedger::count()]);
    }

    #[DataProvider('auditReaders')]
    public function test_alasan_privat_audit_tidak_dibuka_oleh_izin_audit_umum_dan_pencabutan_langsung_berlaku(string $role): void
    {
        [$leave, $admin] = $this->fixture();
        $reason = 'Alasan administratif privat untuk pengujian audit.';
        app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $admin, $reason);
        $audit = AuditLog::query()->where('auditable_id', $leave->id)
            ->where('new_values->operation', 'administrative_postponement')->sole();
        $before = (array) DB::table('audit_logs')->find($audit->id);
        $reader = User::factory()->create([
            'role' => $role,
            'employee_id' => $role === 'kepala_bagian' ? Employee::factory()->create()->id : null,
        ]);
        if ($role === 'kepala_bagian') {
            SupervisorAssignment::query()->create([
                'employee_id' => $leave->employee_id, 'supervisor_id' => $reader->employee_id,
                'tanggal_mulai' => '2026-09-01',
            ]);
        }
        $auditPermission = Permission::query()->where('name', 'audit_logs.read')->sole();
        $readerRole = Role::query()->where('name', $role)->sole();
        $readerRole->permissions()->syncWithoutDetaching([$auditPermission->id]);
        $urls = ['/dashboard/audit', '/dashboard/audit/'.$audit->id, '/api/v1/audit-log?auditable_type=LeaveRequest'];

        $this->actingAs($reader);
        foreach ($urls as $url) {
            $this->get($url)->assertOk()->assertDontSee($reason);
        }

        $this->grant($role);
        foreach ($urls as $url) {
            $this->get($url)->assertOk()->assertSee($reason);
        }
        $readerRole->permissions()->detach(
            Permission::query()->where('name', self::PERMISSION)->sole()->id,
        );
        foreach ($urls as $url) {
            $this->get($url)->assertOk()->assertDontSee($reason);
        }
        $readerRole->permissions()->detach($auditPermission->id);
        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->assertSame($before, (array) DB::table('audit_logs')->find($audit->id));
        $this->assertSame($reason, $audit->fresh()->new_values['reason']);
    }

    public static function auditReaders(): array
    {
        return [['super_admin'], ['pimpinan'], ['kepala_bagian']];
    }

    public function test_pemilik_dapat_membaca_alasan_di_audit_tanpa_permission_penangguhan(): void
    {
        [$leave, $admin] = $this->fixture();
        $reason = 'Alasan privat milik pemohon.';
        app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $admin, $reason);
        $reader = User::factory()->pegawai()->create(['employee_id' => $leave->employee_id]);
        Role::query()->where('name', 'pegawai')->sole()->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'audit_logs.read')->sole()->id,
        ]);

        $this->actingAs($reader)->getJson('/api/v1/audit-log?auditable_type=LeaveRequest')
            ->assertOk()->assertJsonPath('data.0.new_values.reason', $reason);
    }

    public function test_permission_audit_dan_penangguhan_tidak_membuka_alasan_target_di_luar_scope(): void
    {
        [$leave, $admin] = $this->fixture();
        $reason = 'Alasan privat pegawai di luar scope.';
        app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $admin, $reason);
        $reader = User::factory()->pegawai()->create(['employee_id' => Employee::factory()->create()->id]);
        $this->grant('pegawai');
        Role::query()->where('name', 'pegawai')->sole()->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'audit_logs.read')->sole()->id,
        ]);
        $this->actingAs($reader);

        $this->getJson('/api/v1/audit-log?auditable_type=LeaveRequest')
            ->assertOk()->assertJsonPath('total', 0)->assertDontSee($reason);
        $this->get('/dashboard/audit?modul=LeaveRequest')->assertOk()->assertDontSee($reason);
        $audit = AuditLog::query()->where('auditable_id', $leave->id)
            ->where('new_values->operation', 'administrative_postponement')->sole();
        $this->get('/dashboard/audit/'.$audit->id)->assertNotFound();
    }

    public function test_redaksi_audit_dibatch_tanpa_menambah_query_per_baris(): void
    {
        [$leave, $admin] = $this->fixture();
        app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $admin, 'Alasan privat batch.');
        $audit = AuditLog::query()->where('auditable_id', $leave->id)
            ->where('new_values->operation', 'administrative_postponement')->sole();
        foreach (range(1, 9) as $index) {
            $audit->replicate()->save();
        }
        $logs = AuditLog::query()->where('auditable_id', $leave->id)->get();
        $payload = app(AuditLogReadPayload::class);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $payload->forLogs($logs->take(1), $admin);
            $singleCount = count(DB::getQueryLog());
            DB::flushQueryLog();
            $batch = $payload->forLogs($logs, $admin);
            $this->assertCount(10, $batch);
            $this->assertSame($singleCount, count(DB::getQueryLog()));
            $this->assertSame('Alasan privat batch.', $batch->get($audit->id)['new_values']['reason']);
        } finally {
            DB::disableQueryLog();
        }
    }

    #[DataProvider('unprivilegedRoles')]
    public function test_role_tanpa_permission_tidak_dapat_menangguhkan(string $role): void
    {
        [$leave] = $this->fixture();
        $actor = User::factory()->create(['role' => $role, 'employee_id' => $leave->employee_id]);
        $this->actingAs($actor)->post($this->endpoint($leave), ['alasan' => 'Bukan pemegang izin.'])->assertForbidden();
        $this->assertSame('disetujui', $leave->fresh()->status);
    }

    public static function unprivilegedRoles(): array
    {
        return [['super_admin'], ['pimpinan'], ['kepala_bagian'], ['pegawai']];
    }

    public function test_permission_configurable_dan_pencabutannya_langsung_berlaku(): void
    {
        [$leave] = $this->fixture();
        $actor = User::factory()->superAdmin()->create();
        $this->grant('super_admin');
        $this->actingAs($actor)->post($this->endpoint($leave), ['alasan' => 'Izin khusus sudah diberikan.'])->assertRedirect(route('cuti.show', $leave));

        [$second] = $this->fixture();
        Role::query()->where('name', 'super_admin')->sole()->permissions()->detach(Permission::query()->where('name', self::PERMISSION)->sole()->id);
        $this->post($this->endpoint($second), ['alasan' => 'Izin sudah dicabut.'])->assertForbidden();
        $this->assertSame('disetujui', $second->fresh()->status);
    }

    public function test_permission_saja_tidak_membuka_target_unrelated_dan_action_tidak_bypass(): void
    {
        [$leave] = $this->fixture();
        $actor = User::factory()->create(['role' => 'pegawai', 'employee_id' => Employee::factory()->create()->id]);
        $this->grant('pegawai');
        $this->actingAs($actor)->post($this->endpoint($leave), ['alasan' => 'Target pegawai lain.'])->assertForbidden();

        $this->expectException(AuthorizationException::class);
        app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $actor, 'Target pegawai lain.');
    }

    public function test_owner_dengan_assignment_permission_boleh_bertindak(): void
    {
        [$leave] = $this->fixture();
        $actor = User::factory()->create(['role' => 'pegawai', 'employee_id' => $leave->employee_id]);
        $this->grant('pegawai');
        $this->actingAs($actor)->post($this->endpoint($leave), ['alasan' => 'Keputusan sesuai izin yang diberikan.'])->assertRedirect(route('cuti.show', $leave));
        $this->assertSame('ditangguhkan_administratif', $leave->fresh()->status);
    }

    #[DataProvider('invalidReasons')]
    public function test_alasan_tidak_valid_ditolak_sebelum_mutasi(string $reason): void
    {
        [$leave, $admin] = $this->fixture();
        $this->actingAs($admin)->post($this->endpoint($leave), ['alasan' => $reason])
            ->assertSessionHasErrors(['alasan'], null, 'administrativePostponement');
        $this->assertSame('disetujui', $leave->fresh()->status);
    }

    public static function invalidReasons(): array
    {
        return [['   '], [str_repeat('a', 501)]];
    }

    #[DataProvider('notificationPollingRoutes')]
    public function test_polling_notifikasi_mempertahankan_error_sampai_detail_ditampilkan(string $pollingRoute): void
    {
        [$leave, $admin] = $this->fixture();
        $detail = route('cuti.show', $leave);
        $message = 'Kolom alasan penangguhan administratif wajib diisi.';

        $this->actingAs($admin)->from($detail)->post($this->endpoint($leave), ['alasan' => '   '])
            ->assertRedirect($detail)
            ->assertSessionHasErrors(['alasan' => $message], null, 'administrativePostponement');

        // Beberapa pembacaan latar belakang tidak boleh menghabiskan flash milik halaman tujuan.
        for ($poll = 0; $poll < 2; $poll++) {
            $this->getJson(route($pollingRoute), ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()
                ->assertDontSee($message);
        }

        $this->get($detail)->assertOk()
            ->assertSee($message)
            ->assertSee('id="administrative-reason-error"', false)
            ->assertSee('aria-invalid="true"', false);

        // Setelah halaman tujuan ditampilkan, polling tidak boleh menghidupkan kembali error lama.
        $this->getJson(route($pollingRoute), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->get($detail)->assertOk()
            ->assertDontSee($message)
            ->assertDontSee('id="administrative-reason-error"', false);

        $leave->refresh();
        $this->assertSame('disetujui', $leave->status);
        $this->assertNull($leave->administratively_postponed_at);
        $this->assertSame('active', $leave->usageRecord->fresh()->record_status);
    }

    public static function notificationPollingRoutes(): array
    {
        return [
            'daftar notifikasi' => ['api.v1.notifikasi.index'],
            'jumlah belum dibaca' => ['api.v1.notifikasi.jumlah-belum-dibaca'],
        ];
    }

    public function test_polling_notifikasi_mempertahankan_input_alasan_untuk_diperbaiki(): void
    {
        [$leave, $admin] = $this->fixture();
        $detail = route('cuti.show', $leave);
        $reason = str_repeat('a', 501);

        $this->actingAs($admin)->from($detail)->post($this->endpoint($leave), ['alasan' => $reason])
            ->assertSessionHasErrors(['alasan'], null, 'administrativePostponement');
        $this->getJson(route('api.v1.notifikasi.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertDontSee($reason);

        $this->get($detail)->assertOk()
            ->assertSee($reason)
            ->assertSee('id="administrative-reason-error"', false);
        $this->get($detail)->assertOk()->assertDontSee($reason);
    }

    public function test_alasan_array_ditolak_dan_detail_validasi_tetap_dapat_dirender(): void
    {
        [$leave, $admin] = $this->fixture();
        $detail = route('cuti.show', $leave);

        $this->actingAs($admin)->from($detail)->post($this->endpoint($leave), ['alasan' => ['Input bukan teks']])
            ->assertRedirect($detail)
            ->assertSessionHasErrors(['alasan'], null, 'administrativePostponement');

        $this->withoutExceptionHandling();
        $this->get($detail)->assertOk()
            ->assertSee('aria-invalid="true"', false)
            ->assertDontSee('Input bukan teks');
        $leave->refresh();
        $this->assertSame('disetujui', $leave->status);
        $this->assertNull($leave->administratively_postponed_at);
        $this->assertSame('active', $leave->usageRecord->fresh()->record_status);
    }

    #[DataProvider('closedDates')]
    public function test_hari_mulai_dan_hari_lampau_ditolak(string $date): void
    {
        [$leave, $admin] = $this->fixture($date);
        $this->actingAs($admin)->post($this->endpoint($leave), ['alasan' => 'Sudah melewati batas tanggal.'])
            ->assertSessionHasErrors(['tanggal_mulai'], null, 'administrativePostponement');
        $this->assertSame('disetujui', $leave->fresh()->status);
        $this->assertSame('active', $leave->usageRecord->fresh()->record_status);
    }

    public static function closedDates(): array
    {
        return [['2026-09-06'], ['2026-09-01']];
    }

    public function test_pergantian_hari_wita_menutup_form_yang_dibuka_sebelumnya(): void
    {
        [$leave, $admin] = $this->fixture('2026-09-07');
        Carbon::setTestNow(Carbon::parse('2026-09-06 16:00:00', 'UTC'));
        $this->actingAs($admin)->post($this->endpoint($leave), ['alasan' => 'Dikirim setelah tengah malam WITA.'])
            ->assertSessionHasErrors(['tanggal_mulai'], null, 'administrativePostponement');
        $this->assertSame('disetujui', $leave->fresh()->status);
    }

    public function test_timestamp_keputusan_mempertahankan_instant_bila_timezone_aplikasi_utc(): void
    {
        $originalTimezone = date_default_timezone_get();
        $originalConfig = config('app.timezone');
        config()->set('app.timezone', 'UTC');
        date_default_timezone_set('UTC');
        try {
            [$leave, $admin] = $this->fixture();
            $expected = Carbon::now('Asia/Makassar');
            $this->actingAs($admin)->post($this->endpoint($leave), ['alasan' => 'Keputusan lintas zona waktu.'])
                ->assertRedirect(route('cuti.show', $leave));
            $reloaded = $leave->fresh()->administratively_postponed_at;
            $this->assertSame($expected->getTimestamp(), $reloaded->getTimestamp());
            $this->assertSame($expected->format('Y-m-d H:i:s'), $reloaded->copy()->setTimezone('Asia/Makassar')->format('Y-m-d H:i:s'));
            $audit = AuditLog::query()->where('auditable_id', $leave->id)->where('new_values->operation', 'administrative_postponement')->sole();
            $this->assertSame($expected->toIso8601String(), $audit->new_values['administratively_postponed_at']);
        } finally {
            config()->set('app.timezone', $originalConfig);
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_uuid_tidak_valid_menghasilkan_404(): void
    {
        $this->actingAsRole('admin_kepegawaian');
        $this->post('/cuti/bukan-uuid/penangguhan-administratif', ['alasan' => 'Permintaan invalid.'])->assertNotFound();
    }

    public function test_aktor_dalam_scope_dengan_permission_boleh_dan_payload_identitas_diabaikan(): void
    {
        [$leave, $admin] = $this->fixture();
        $actor = User::factory()->pegawai()->create(['employee_id' => $leave->employee_id]);
        $this->grant('pegawai');
        $this->actingAs($actor)->post($this->endpoint($leave), [
            'alasan' => 'Keputusan aktor dalam scope.', 'actor_id' => $admin->id,
            'administratively_postponed_by' => $admin->id, 'status' => 'disetujui', 'jumlah_hari_kerja' => 99,
        ])->assertRedirect(route('cuti.show', $leave));
        $this->assertSame($actor->id, $leave->fresh()->administratively_postponed_by);
        $this->assertSame(2, $leave->fresh()->jumlah_hari_kerja);
    }

    #[DataProvider('outOfScopeActors')]
    public function test_izin_baca_dan_snapshot_tidak_memperluas_scope_mutasi(bool $snapshot): void
    {
        [$leave, $admin] = $this->fixture();
        $actor = User::factory()->pegawai()->create([
            'employee_id' => $snapshot
                ? $leave->steps()->firstOrFail()->approver_employee_id
                : Employee::factory()->create()->id,
        ]);
        $this->grant('pegawai');
        if (! $snapshot) {
            Role::query()->where('name', 'pegawai')->sole()->permissions()->syncWithoutDetaching([
                Permission::query()->where('name', 'cuti.read_all')->sole()->id,
            ]);
        }

        $this->actingAs($actor)->get(route('cuti.show', $leave))->assertOk()
            ->assertViewHas('canAdministrativelyPostpone', false);
        $this->post($this->endpoint($leave), ['alasan' => 'Mutasi di luar scope.'])->assertForbidden();
        try {
            app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $actor, 'Jalur Action juga wajib scoped.');
            $this->fail('Action harus menolak target di luar scope.');
        } catch (AuthorizationException) {
            $this->assertSame('disetujui', $leave->fresh()->status);
            $this->assertSame('active', $leave->usageRecord->fresh()->record_status);
        }

        $this->actingAs($admin)->post($this->endpoint($leave), ['alasan' => 'Alasan administratif privat.'])
            ->assertSessionHasNoErrors();
        $this->actingAs($actor)->get(route('cuti.show', $leave))->assertOk()
            ->assertDontSee('Alasan administratif privat.');
    }

    public static function outOfScopeActors(): array
    {
        return ['snapshot approver' => [true], 'permission monitoring' => [false]];
    }

    #[DataProvider('administrativeScopes')]
    public function test_pengelola_memakai_scope_kanonis_tanpa_izin_baca_global(string $role, string $start, ?string $end, bool $allowed): void
    {
        [$leave] = $this->fixture();
        $actor = User::factory()->create(['role' => $role, 'employee_id' => Employee::factory()->create()->id]);
        $this->grant($role);
        Role::query()->where('name', $role)->sole()->permissions()->detach(
            Permission::query()->where('name', 'cuti.read_all')->sole()->id,
        );
        if ($role === 'kepala_bagian') {
            SupervisorAssignment::create([
                'employee_id' => $leave->employee_id, 'kepala_bagian_id' => $actor->employee_id,
                'tanggal_mulai' => $start, 'tanggal_berakhir' => $end,
            ]);
        }

        $this->actingAs($actor);
        if (! $allowed) {
            $this->get(route('cuti.show', $leave))->assertForbidden();
            $this->post($this->endpoint($leave), ['alasan' => 'Assignment tidak efektif.'])->assertForbidden();
            $this->assertSame('active', $leave->usageRecord->fresh()->record_status);

            return;
        }

        $this->get(route('cuti.show', $leave))->assertOk()->assertViewHas('canAdministrativelyPostpone', true);
        $response = $this->post($this->endpoint($leave), ['alasan' => 'Keputusan pengelola dalam scope.'])
            ->assertRedirect(route('cuti.show', $leave))->assertSessionHasNoErrors();
        $this->followRedirects($response)->assertOk()->assertSee('Keputusan pengelola dalam scope.');
        $this->assertSame('cancelled', $leave->usageRecord->fresh()->record_status);
        $this->assertSame($actor->id, $leave->fresh()->administratively_postponed_by);
    }

    public static function administrativeScopes(): array
    {
        return [
            'Pimpinan global' => ['pimpinan', '2026-09-01', null, true],
            'bawahan efektif' => ['kepala_bagian', '2026-09-06', '2026-09-06', true],
            'assignment masa depan' => ['kepala_bagian', '2026-09-07', null, false],
            'assignment berakhir' => ['kepala_bagian', '2026-09-01', '2026-09-05', false],
        ];
    }

    public function test_action_memakai_aktor_eksplisit_bukan_auth_dan_menolak_http_mismatch(): void
    {
        [$leave, $admin] = $this->fixture();
        $unrelated = User::factory()->pegawai()->create();
        $this->actingAs($unrelated);
        app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $admin, 'Aktor eksplisit yang berwenang.');
        $audit = AuditLog::query()->where('auditable_id', $leave->id)->where('new_values->operation', 'administrative_postponement')->sole();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('admin_kepegawaian', $audit->new_values['_effective_role']);

        [$second] = $this->fixture();
        $http = Request::create($this->endpoint($second), 'POST');
        $http->setUserResolver(fn (): User => $unrelated);
        try {
            app(RecordAdministrativeLeavePostponementAction::class)->execute($second, $admin, 'Identitas tidak cocok.', $http);
            $this->fail('Identitas HTTP berbeda harus ditolak.');
        } catch (AuthorizationException) {
            $this->assertSame('disetujui', $second->fresh()->status);
        }
    }

    #[DataProvider('invalidFinalSnapshots')]
    public function test_snapshot_belum_final_ditolak_tanpa_mutasi(string $state): void
    {
        [$leave, $admin] = $this->fixture();
        if ($state === 'missing') {
            $leave->steps()->delete();
        } elseif ($state === 'no_final') {
            $leave->steps()->update(['is_final' => false]);
        } else {
            $leave->steps()->where('is_final', true)->update(['status' => $state]);
        }
        $this->actingAs($admin)->post($this->endpoint($leave), ['alasan' => 'Snapshot tidak lengkap.'])
            ->assertSessionHasErrors(['status'], null, 'administrativePostponement');
        $this->assertSame('disetujui', $leave->fresh()->status);
        $this->assertSame('active', $leave->usageRecord->fresh()->record_status);
    }

    public static function invalidFinalSnapshots(): array
    {
        return [['missing'], ['no_final'], ['active'], ['pending'], ['postponed']];
    }

    public function test_reservasi_mentah_harus_nol_per_tahun_bukan_hanya_total_request(): void
    {
        [$leave, $admin] = $this->fixture();
        foreach ([[2025, 1], [2026, -1]] as [$year, $amount]) {
            LeaveBalanceReservationEvent::create([
                'employee_id' => $leave->employee_id, 'leave_request_id' => $leave->id,
                'tahun' => $year, 'event_type' => 'adjusted', 'amount' => $amount, 'occurred_at' => now(),
            ]);
        }
        $this->actingAs($admin)->post($this->endpoint($leave), ['alasan' => 'Reservasi final inkonsisten.'])
            ->assertSessionHasErrors(['status'], null, 'administrativePostponement');
        $this->assertSame('disetujui', $leave->fresh()->status);
    }

    #[DataProvider('failurePoints')]
    public function test_kegagalan_ledger_rekalkulasi_dan_audit_rollback_seluruh_keputusan(string $point): void
    {
        [$leave, $admin] = $this->fixture();
        $before = [AuditLog::count(), LeaveBalanceLedger::count(), SimpegNotification::count()];
        $balance = LeaveBalance::query()->where('employee_id', $leave->employee_id)->orderBy('tahun')->get()->toArray();
        $model = match ($point) {
            'ledger' => LeaveBalanceLedger::class,
            'recalculation' => LeaveBalance::class,
            default => AuditLog::class,
        };
        $event = $point === 'recalculation' ? 'updating' : 'creating';
        Event::listen('eloquent.'.$event.': '.$model, function ($record) use ($point): void {
            if ($point === 'recalculation'
                || ($point === 'ledger' && $record->event_type === 'usage_fact_cancelled')
                || ($point === 'audit' && ($record->new_values['operation'] ?? null) === 'administrative_postponement')) {
                throw new \RuntimeException('Gagal terarah.');
            }
        });
        try {
            app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $admin, 'Alasan rahasia yang dibatalkan.');
            $this->fail('Kegagalan write kritis tidak boleh ditelan.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Gagal terarah.', $exception->getMessage());
        }
        $this->assertSame('disetujui', $leave->fresh()->status);
        $this->assertNull($leave->fresh()->administratively_postponed_at);
        $this->assertSame('active', $leave->usageRecord->fresh()->record_status);
        $this->assertSame($before, [AuditLog::count(), LeaveBalanceLedger::count(), SimpegNotification::count()]);
        $this->assertSame($balance, LeaveBalance::query()->where('employee_id', $leave->employee_id)->orderBy('tahun')->get()->toArray());
        Queue::assertNothingPushed();
    }

    public static function failurePoints(): array
    {
        return [['ledger'], ['recalculation'], ['audit']];
    }

    public function test_notifikasi_hanya_pemohon_setelah_commit_terluar_tanpa_alasan_atau_whatsapp(): void
    {
        [$leave, $admin] = $this->fixture();
        DB::transaction(function () use ($leave, $admin): void {
            app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $admin, 'Alasan rahasia administratif.');
            $this->assertSame(0, SimpegNotification::query()->where('type', 'cuti.ditangguhkan_administratif')->count());
            Queue::assertNothingPushed();
        });
        $notification = SimpegNotification::query()->where('type', 'cuti.ditangguhkan_administratif')->sole();
        $this->assertSame($leave->employee_id, $notification->user_id);
        $this->assertSame($leave->id, $notification->data['leave_request_id']);
        $this->assertStringNotContainsString('Alasan rahasia administratif.', $notification->toJson());
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $leave->employee_id
            && $job->eventKey === 'cuti.ditangguhkan_administratif'
            && ! str_contains(serialize($job), 'Alasan rahasia administratif.'));
        $this->assertDatabaseCount('whatsapp_notification_outboxes', 0);
        $this->assertFalse(app(NotificationEventCatalog::class)->supportsChannel('cuti.ditangguhkan_administratif', 'whatsapp_business'));
    }

    public function test_email_persetujuan_tertunda_dan_retry_dilewati_setelah_penangguhan_administratif(): void
    {
        $queue = Queue::fake();
        Mail::fake();
        [$leave, $admin] = $this->fixture();
        $employee = $leave->employee;
        $employee->update(['email_pribadi' => 'pemohon@example.test']);
        app(NotificationService::class)->createForEmployee(
            $employee, 'cuti.disetujui', 'Pengajuan Cuti Disetujui',
            'Pengajuan cuti Anda telah disetujui sepenuhnya.',
            ['leave_request_id' => $leave->id, 'url' => route('cuti.show', $leave, false)],
        );
        $approvalJob = $queue->pushed(SendSimpegNotificationEmailJob::class)->sole();

        app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $admin, 'Jadwal kegiatan instansi berubah.');

        $postponementJob = $queue->pushed(SendSimpegNotificationEmailJob::class)
            ->sole(fn (SendSimpegNotificationEmailJob $job): bool => $job->eventKey === 'cuti.ditangguhkan_administratif');
        app()->call([$postponementJob, 'handle']);
        app()->call([$approvalJob, 'handle']);
        app()->call([$approvalJob, 'handle']);

        Mail::assertNotSent(SimpegNotificationMail::class, fn (SimpegNotificationMail $mail): bool => $mail->title === 'Pengajuan Cuti Disetujui');
        Mail::assertSent(SimpegNotificationMail::class, fn (SimpegNotificationMail $mail): bool => $mail->hasTo('pemohon@example.test')
            && $mail->title === 'Cuti Ditangguhkan Secara Administratif');
        Mail::assertSentCount(1);
        $this->assertSame('ditangguhkan_administratif', $leave->fresh()->status);
        // Delivery lama dilewati tanpa menghapus jejak pemberitahuan kedua keputusan di aplikasi.
        $this->assertSame(2, SimpegNotification::query()->where('user_id', $employee->id)
            ->whereIn('type', ['cuti.disetujui', 'cuti.ditangguhkan_administratif'])->count());
    }

    public function test_rollback_transaksi_terluar_tidak_mengirim_notifikasi(): void
    {
        [$leave, $admin] = $this->fixture();
        DB::beginTransaction();
        try {
            app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $admin, 'Transaksi luar tidak disimpan.');
            $this->assertSame(0, SimpegNotification::count());
        } finally {
            DB::rollBack();
        }
        $this->assertSame('disetujui', $leave->fresh()->status);
        $this->assertSame(0, SimpegNotification::count());
        Queue::assertNothingPushed();
    }

    public function test_seeder_dan_migrasi_tidak_menimpa_assignment_dan_policy_operator(): void
    {
        $this->grant('super_admin');
        Role::query()->where('name', 'admin_kepegawaian')->sole()->permissions()->detach(Permission::query()->where('name', self::PERMISSION)->sole()->id);
        DB::table('notification_event_channels')->where('event_key', 'cuti.ditangguhkan_administratif')->update(['is_enabled' => false]);
        $this->seedRbac();
        $this->seedReferenceData();
        $migration = require database_path('migrations/2026_09_06_000002_add_administrative_leave_postponement_access_and_notifications.php');
        $migration->up();
        $this->assertTrue(User::factory()->superAdmin()->create()->hasPermission(self::PERMISSION));
        $this->assertFalse(User::factory()->adminKepegawaian()->create()->hasPermission(self::PERMISSION));
        $this->assertSame(0, DB::table('notification_event_channels')->where('event_key', 'cuti.ditangguhkan_administratif')->where('is_enabled', true)->count());
    }

    public function test_kegagalan_notifikasi_dilaporkan_tanpa_menyamarkan_keputusan_yang_sudah_commit(): void
    {
        [$leave, $admin] = $this->fixture();
        Exceptions::fake();
        $this->mock(NotificationService::class, function (MockInterface $mock): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('createForEmployee');
            $expectation->once()->andThrow(new \RuntimeException('Delivery gagal terarah.'));
        });

        $result = app(RecordAdministrativeLeavePostponementAction::class)->execute($leave, $admin, 'Keputusan tetap tersimpan.');

        $this->assertSame('ditangguhkan_administratif', $result->status);
        $this->assertSame('ditangguhkan_administratif', $leave->fresh()->status);
        $this->assertSame('cancelled', $leave->usageRecord->fresh()->record_status);
        $this->assertSame(1, AuditLog::query()->where('auditable_id', $leave->id)->where('new_values->operation', 'administrative_postponement')->count());
        Exceptions::assertReported(fn (\RuntimeException $exception): bool => $exception->getMessage() === 'Delivery gagal terarah.');
    }

    #[DataProvider('invalidSources')]
    public function test_fakta_hilang_atau_tidak_matching_rollback_metadata_request(string $source): void
    {
        [$leave, $admin] = $this->fixture(recordUsage: $source !== 'missing');
        if ($source === 'mismatch') {
            // Kerusakan sumber direpresentasikan di request sebelum keputusan, tanpa mengubah fakta immutable.
            $leave->forceFill(['jumlah_hari_kerja' => 3])->save();
        }
        $before = [AuditLog::count(), LeaveBalanceLedger::count()];
        $this->actingAs($admin)->post($this->endpoint($leave), ['alasan' => 'Data sumber harus diperiksa.'])
            ->assertSessionHasErrors(['usage_record'], null, 'administrativePostponement');
        $this->assertSame('disetujui', $leave->fresh()->status);
        $this->assertNull($leave->fresh()->administratively_postponed_at);
        $this->assertSame($before, [AuditLog::count(), LeaveBalanceLedger::count()]);
        $this->assertSame(0, SimpegNotification::count());
        Queue::assertNothingPushed();
    }

    public static function invalidSources(): array
    {
        return [['missing'], ['mismatch']];
    }

    #[DataProvider('nonApprovedStatuses')]
    public function test_status_nonapproved_dan_terminal_lain_tidak_dapat_diproses(string $status): void
    {
        [$leave, $admin] = $this->fixture(recordUsage: false);
        $leave->forceFill(['status' => $status])->save();
        $this->actingAs($admin)->post($this->endpoint($leave), ['alasan' => 'Bukan approval final.'])
            ->assertSessionHasErrors(['status'], null, 'administrativePostponement');
        $this->assertSame($status, $leave->fresh()->status);
    }

    public static function nonApprovedStatuses(): array
    {
        return [['menunggu_approval'], ['ditangguhkan'], ['dibatalkan']];
    }

    public function test_role_simulasi_mengikuti_permission_efektif_dan_diaudit(): void
    {
        [$leave] = $this->fixture();
        $actor = User::factory()->superAdmin()->create(['temporary_role' => 'admin_kepegawaian']);
        $this->actingAs($actor)->post($this->endpoint($leave), ['alasan' => 'Keputusan memakai role efektif.'])->assertRedirect(route('cuti.show', $leave));
        $audit = AuditLog::query()->where('auditable_id', $leave->id)->where('new_values->operation', 'administrative_postponement')->sole();
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame('admin_kepegawaian', $audit->new_values['_effective_role']);
        $this->assertSame('super_admin', $audit->new_values['_original_role']);
        $this->assertTrue($audit->new_values['_simulation']);
    }

    /** @return array{LeaveRequest, User} */
    private function fixture(string $start = '2026-09-14', bool $recordUsage = true): array
    {
        $employee = Employee::factory()->create(['jenis_pegawai_id' => RefJenisPegawai::query()->where('nama', 'PNS')->sole()->id]);
        Appointment::create(['employee_id' => $employee->id, 'jenis_pengangkatan' => 'PNS', 'tmt_pengangkatan' => '2020-01-01']);
        $admin = User::factory()->adminKepegawaian()->create();
        app(LeaveBalanceRecalculationService::class)->recalculate($employee, 2024, $admin, 'Pemakaian awal.');
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => RefJenisCuti::query()->where('code', 'tahunan')->sole()->id,
            'tanggal_mulai' => $start,
            'tanggal_selesai' => Carbon::parse($start)->addDay()->toDateString(),
            'jumlah_hari_kerja' => 2,
            'alasan' => 'Pengajuan sah untuk pengujian.',
            'status' => 'disetujui',
        ]);
        foreach (['kepala_bagian', 'pybmc'] as $index => $type) {
            $leave->steps()->create([
                'step_order' => $index + 1, 'step_type' => $type,
                'role_label' => $type === 'pybmc' ? 'PYBMC' : 'Atasan Langsung',
                'approver_employee_id' => Employee::factory()->create()->id,
                'status' => 'approved',
                'is_final' => $type === 'pybmc', 'acted_at' => now(),
            ]);
        }
        foreach ([['reserved', 2], ['converted', -2]] as [$event, $amount]) {
            LeaveBalanceReservationEvent::create([
                'employee_id' => $employee->id, 'leave_request_id' => $leave->id, 'tahun' => 2026,
                'event_type' => $event, 'amount' => $amount, 'occurred_at' => now(),
            ]);
        }
        if ($recordUsage) {
            app(LeaveUsageRecordService::class)->recordApprovedRequest($leave, $admin);
        }

        return [$leave, $admin];
    }

    private function grant(string $role): void
    {
        Role::query()->where('name', $role)->sole()->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', self::PERMISSION)->sole()->id,
        ]);
    }

    private function endpoint(LeaveRequest $leave): string
    {
        return '/cuti/'.$leave->id.'/penangguhan-administratif';
    }
}
