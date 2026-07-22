<?php

namespace Tests\Feature;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\DeclineLeaveAction;
use App\Jobs\SendSimpegNotificationEmailJob;
use App\Mail\SimpegNotificationMail;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefNotificationChannel;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class EmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cuti_submission_queues_email_to_primary_recipient(): void
    {
        Queue::fake();
        $employee = Employee::factory()->create(['email' => 'atasan@example.test']);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'cuti.pengajuan_baru',
            title: 'Pengajuan Cuti Menunggu Persetujuan',
            body: 'Pegawai mengajukan cuti.',
            data: ['url' => '/dashboard/cuti'],
        );

        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
        Queue::assertPushed(
            SendSimpegNotificationEmailJob::class,
            fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id
        );
    }

    public function test_disabled_email_channel_keeps_in_app_notification_but_does_not_queue_email(): void
    {
        Queue::fake();
        RefNotificationChannel::query()->where('code', 'email')->update(['is_enabled' => false]);
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'cuti.pengajuan_baru',
            title: 'Pengajuan Cuti Menunggu Persetujuan',
            body: 'Pegawai mengajukan cuti.',
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'cuti.pengajuan_baru',
        ]);
        Queue::assertNotPushed(SendSimpegNotificationEmailJob::class);
    }

    public function test_disabled_in_app_channel_keeps_email_delivery_available(): void
    {
        Queue::fake();
        RefNotificationChannel::query()->where('code', 'in_app')->update(['is_enabled' => false]);
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);

        $notification = app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'cuti.pengajuan_baru',
            title: 'Pengajuan Cuti Menunggu Persetujuan',
            body: 'Pegawai mengajukan cuti.',
        );

        $this->assertNull($notification);
        $this->assertDatabaseMissing('notifications', ['user_id' => $employee->id]);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
    }

    public function test_cuti_revision_request_queues_email_to_primary_recipient(): void
    {
        Queue::fake();
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'cuti.perlu_perubahan',
            title: 'Pengajuan Cuti Perlu Perubahan',
            body: 'Lengkapi data pengajuan cuti.',
            data: ['url' => '/dashboard/cuti'],
        );

        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
        Queue::assertPushed(
            SendSimpegNotificationEmailJob::class,
            fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id
        );
    }

    public function test_cuti_decline_queues_email_to_primary_recipient(): void
    {
        Queue::fake();
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'cuti.tidak_disetujui',
            title: 'Pengajuan Cuti Tidak Disetujui',
            body: 'Pengajuan cuti tidak dapat disetujui.',
            data: ['url' => '/dashboard/cuti'],
        );

        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
        Queue::assertPushed(
            SendSimpegNotificationEmailJob::class,
            fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id
        );
    }

    public function test_ews_email_goes_to_employee_admin_kepegawaian_and_super_admin(): void
    {
        Queue::fake();
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $adminEmployee = Employee::factory()->create(['email' => 'admin@example.test']);
        $superEmployee = Employee::factory()->create(['email' => 'super@example.test']);
        $pimpinanEmployee = Employee::factory()->create(['email' => 'pimpinan@example.test']);
        User::factory()->adminKepegawaian()->create(['employee_id' => $adminEmployee->id]);
        User::factory()->superAdmin()->create(['employee_id' => $superEmployee->id]);
        User::factory()->pimpinan()->create(['employee_id' => $pimpinanEmployee->id]);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'ews.kgb',
            title: 'Peringatan EWS: KGB',
            body: 'Jadwal KGB mendekat.',
            data: ['ews_alert_id' => 'alert-1'],
        );

        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 3);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $adminEmployee->id);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $superEmployee->id);
        Queue::assertNotPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $pimpinanEmployee->id);
    }

    public function test_ews_email_recipients_are_deduplicated(): void
    {
        Queue::fake();
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $superEmployee = Employee::factory()->create(['email' => 'super@example.test']);
        User::factory()->adminKepegawaian()->create(['employee_id' => $employee->id]);
        User::factory()->superAdmin()->create(['employee_id' => $superEmployee->id]);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'ews.kgb',
            title: 'Peringatan EWS: KGB',
            body: 'Jadwal KGB mendekat.',
            data: ['ews_alert_id' => 'alert-1'],
        );

        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 2);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $superEmployee->id);
    }

    public function test_non_eligible_promotion_does_not_queue_email_until_admin_policy_is_decided(): void
    {
        Queue::fake();
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $adminEmployee = Employee::factory()->create(['email' => 'admin@example.test']);
        $superEmployee = Employee::factory()->create(['email' => 'super@example.test']);
        User::factory()->adminKepegawaian()->create(['employee_id' => $adminEmployee->id]);
        User::factory()->superAdmin()->create(['employee_id' => $superEmployee->id]);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'ews.kenaikan_pangkat',
            title: 'Peringatan EWS: Kenaikan Pangkat',
            body: 'Status kenaikan pangkat perlu ditinjau.',
            data: ['is_eligible' => false],
        );

        Queue::assertNotPushed(SendSimpegNotificationEmailJob::class);
    }

    public function test_job_has_three_tries(): void
    {
        $job = new SendSimpegNotificationEmailJob('employee-id', 'Judul', 'Isi pesan');

        $this->assertSame(3, $job->tries);
    }

    public function test_job_sends_mail_to_employee_email(): void
    {
        Mail::fake();
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $job = new SendSimpegNotificationEmailJob($employee->id, 'Judul Notifikasi', 'Isi notifikasi', ['url' => '/dashboard']);

        $job->handle();

        Mail::assertSent(SimpegNotificationMail::class, function (SimpegNotificationMail $mail): bool {
            return $mail->hasTo('pegawai@example.test') && $mail->title === 'Judul Notifikasi';
        });
    }

    public function test_kegagalan_email_terminal_tercatat_di_failed_jobs_native(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
            'queue.failed.driver' => 'database-uuids',
            'queue.failed.database' => config('database.default'),
        ]);
        app()->forgetInstance('queue.failer');
        app()->forgetInstance('queue.worker');
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            app('queue.failer')->log(
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->getRawBody(),
                $event->exception,
            );
        });
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP gagal dengan password rahasia-test'));

        $job = new SendSimpegNotificationEmailJob(
            $employee->id,
            'Pengajuan Cuti Disetujui',
            'Pengajuan cuti Anda telah disetujui sepenuhnya.',
        );
        $job->tries = 1;
        Queue::connection('database')->push($job);

        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(maxTries: 1));

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseHas('failed_jobs', [
            'connection' => 'database',
            'queue' => 'default',
        ]);
    }

    public function test_persetujuan_final_dan_notifikasi_in_app_tetap_committed_sebelum_email_diproses(): void
    {
        Queue::fake();
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $pybmc = Employee::factory()->create();
        $leave = $this->makeLeaveRequestWithSteps($employee, [$pybmc]);

        app(ApproveLeaveAction::class)->execute($leave, $pybmc, null, Request::create('/'));

        $this->assertSame('disetujui', $leave->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'cuti.disetujui',
        ]);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class);
    }

    public function test_email_template_contains_title_body_and_cta_without_sensitive_payload(): void
    {
        $html = (new SimpegNotificationMail('Judul Test', 'Detail singkat test.', [
            'url' => '/dashboard',
            'nip' => '199001012020121001',
        ]))->render();

        $this->assertStringContainsString('Judul Test', $html);
        $this->assertStringContainsString('Detail singkat test.', $html);
        $this->assertStringContainsString('Lihat di SIMPEG', $html);
        $this->assertStringNotContainsString('199001012020121001', $html);
    }

    public function test_email_cta_rejects_external_url_payload(): void
    {
        $html = (new SimpegNotificationMail('Judul Test', 'Detail singkat test.', [
            'url' => 'https://example.net/phishing',
        ]))->render();

        $this->assertStringContainsString(route('dashboard'), $html);
        $this->assertStringNotContainsString('https://example.net/phishing', $html);
    }

    public function test_cuti_decline_notification_carries_internal_detail_url(): void
    {
        Queue::fake();
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $approver = Employee::factory()->create();
        $leave = $this->makeLeaveRequestWithSteps($employee, [$approver]);

        app(DeclineLeaveAction::class)->execute($leave, $approver, 'Dokumen pendukung tidak sesuai.', Request::create('/'));

        $notification = SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where('type', 'cuti.tidak_disetujui')
            ->firstOrFail();

        // Pemohon harus diarahkan ke detail pengajuannya lewat path internal relatif, dengan referensi id tetap dibawa.
        $this->assertSame($leave->id, $notification->data['leave_request_id']);
        $this->assertSame("/dashboard/cuti/{$leave->id}", $notification->data['url']);
    }

    public function test_intermediate_approval_notifies_next_approver_with_internal_approval_url(): void
    {
        Queue::fake();
        $employee = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create(['email' => 'kabag@example.test']);
        $pybmc = Employee::factory()->create(['email' => 'pybmc@example.test']);
        $leave = $this->makeLeaveRequestWithSteps($employee, [$kepalaBagian, $pybmc]);

        app(ApproveLeaveAction::class)->execute($leave, $kepalaBagian, null, Request::create('/'));

        $notification = SimpegNotification::query()
            ->where('user_id', $pybmc->id)
            ->where('type', 'cuti.menunggu_persetujuan')
            ->firstOrFail();

        // Approver tahap berikutnya diarahkan ke antrean approval lewat path internal relatif tanpa parameter.
        $this->assertSame($leave->id, $notification->data['leave_request_id']);
        $this->assertSame('/cuti/approval', $notification->data['url']);
    }

    public function test_email_cta_renders_internal_leave_detail_url(): void
    {
        $detailPath = '/dashboard/cuti/0f9c6f7e-1d2b-4c3a-8e5f-123456789abc';

        $html = (new SimpegNotificationMail('Pengajuan Cuti Disetujui', 'Pengajuan cuti Anda telah disetujui.', [
            'url' => $detailPath,
            'leave_request_id' => '0f9c6f7e-1d2b-4c3a-8e5f-123456789abc',
        ]))->render();

        // CTA email harus membuka halaman detail cuti internal, bukan fallback dashboard.
        $this->assertStringContainsString(url($detailPath), $html);
    }

    /**
     * Membuat pengajuan cuti beserta snapshot step aktif pertama untuk menguji wiring notifikasi.
     * Fixture sengaja minimal (tanpa saldo/proof) karena fokus uji hanya pada payload url notifikasi.
     *
     * @param  list<Employee>  $approvers
     */
    private function makeLeaveRequestWithSteps(Employee $employee, array $approvers): LeaveRequest
    {
        $jenis = RefJenisCuti::create([
            'nama' => 'Cuti Sakit',
            'code' => 'cuti_sakit',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);

        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-08',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga.',
            'status' => 'menunggu_approval',
        ]);

        $firstActiveAssigned = false;

        foreach ($approvers as $index => $approver) {
            $order = $index + 1;
            $isFinal = $order === count($approvers);
            $status = 'pending';

            if (! $firstActiveAssigned) {
                $status = 'active';
                $firstActiveAssigned = true;
            }

            $leave->steps()->create([
                'step_order' => $order,
                'step_type' => $isFinal ? 'pybmc' : 'kepala_bagian',
                'role_label' => $isFinal ? 'PYBMC' : 'Kepala Bagian',
                'approver_employee_id' => $approver->id,
                'status' => $status,
                'is_final' => $isFinal,
            ]);
        }

        return $leave;
    }
}
