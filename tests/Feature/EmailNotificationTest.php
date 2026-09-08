<?php

namespace Tests\Feature;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\DeclineLeaveAction;
use App\Jobs\SendSimpegNotificationEmailJob;
use App\Mail\SimpegNotificationMail;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\NotificationEventChannel;
use App\Models\RefJenisCuti;
use App\Models\RefNotificationChannel;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\ReferenceSeeder;
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

    public function test_email_diantrekan_saat_channel_global_dan_kebijakan_event_aktif(): void
    {
        Queue::fake();
        $this->setEventChannelPolicy('cuti.pengajuan_baru', 'email', true);
        $this->setEventChannelPolicy('cuti.pengajuan_baru', 'in_app', true);
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
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
    }

    public function test_kebijakan_email_yang_tidak_ada_memblokir_email_tetapi_in_app_tetap_tersimpan(): void
    {
        Queue::fake();
        $this->setEventChannelPolicy('cuti.pengajuan_baru', 'in_app', true);
        $this->deleteEventChannelPolicy('cuti.pengajuan_baru', 'email');
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

    public function test_kebijakan_email_nonaktif_memblokir_email_tetapi_in_app_tetap_tersimpan(): void
    {
        Queue::fake();
        $this->setEventChannelPolicy('cuti.pengajuan_baru', 'email', false);
        $this->setEventChannelPolicy('cuti.pengajuan_baru', 'in_app', true);
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

    public function test_channel_global_email_nonaktif_memblokir_email_walau_kebijakan_event_aktif(): void
    {
        Queue::fake();
        $this->setEventChannelPolicy('cuti.pengajuan_baru', 'email', true);
        $this->setEventChannelPolicy('cuti.pengajuan_baru', 'in_app', true);
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

    public function test_event_tanpa_dukungan_email_tidak_mengantrikan_job_meski_kebijakan_stale_aktif(): void
    {
        Queue::fake();
        $this->setEventChannelPolicy('import_pegawai', 'email', true);
        $this->setEventChannelPolicy('import_pegawai', 'in_app', true);
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'import_pegawai',
            title: 'Impor Pegawai Selesai',
            body: 'Proses impor pegawai telah selesai.',
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'import_pegawai',
        ]);
        Queue::assertNotPushed(SendSimpegNotificationEmailJob::class);
    }

    public function test_perubahan_status_dengan_kebijakan_email_aktif_mengantrikan_job(): void
    {
        Queue::fake();
        $this->setEventChannelPolicy('status_pegawai.diubah', 'email', true);
        $this->setEventChannelPolicy('status_pegawai.diubah', 'in_app', true);
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'status_pegawai.diubah',
            title: 'Status Kepegawaian Anda Diperbarui',
            body: 'Status kepegawaian Anda telah diperbarui.',
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'status_pegawai.diubah',
        ]);
        Queue::assertPushed(
            SendSimpegNotificationEmailJob::class,
            fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id
                && $job->eventKey === 'status_pegawai.diubah',
        );
    }

    public function test_kebijakan_in_app_yang_tidak_ada_memblokir_persist_tetapi_email_tetap_diantrekan(): void
    {
        Queue::fake();
        $this->setEventChannelPolicy('cuti.pengajuan_baru', 'email', true);
        $this->deleteEventChannelPolicy('cuti.pengajuan_baru', 'in_app');
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

    public function test_kebijakan_in_app_nonaktif_memblokir_persist_tetapi_email_tetap_diantrekan(): void
    {
        Queue::fake();
        $this->setEventChannelPolicy('cuti.pengajuan_baru', 'email', true);
        $this->setEventChannelPolicy('cuti.pengajuan_baru', 'in_app', false);
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

    public function test_channel_global_in_app_nonaktif_memblokir_persist_walau_kebijakan_event_aktif(): void
    {
        Queue::fake();
        $this->setEventChannelPolicy('cuti.pengajuan_baru', 'email', true);
        $this->setEventChannelPolicy('cuti.pengajuan_baru', 'in_app', true);
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

    public function test_ews_normal_mengirim_in_app_dan_email_hanya_ke_pegawai_dan_admin_kepegawaian(): void
    {
        Queue::fake();
        $this->enableEventChannels('ews.kgb');
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $adminEmployee = Employee::factory()->create(['email' => 'admin@example.test']);
        $superEmployee = Employee::factory()->create(['email' => 'super@example.test']);
        User::factory()->adminKepegawaian()->create(['employee_id' => $adminEmployee->id]);
        User::factory()->superAdmin()->create(['employee_id' => $superEmployee->id]);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'ews.kgb',
            title: 'Peringatan EWS: KGB',
            body: 'Jadwal KGB mendekat.',
            data: ['ews_alert_id' => 'alert-1'],
        );

        $this->assertDatabaseHas('notifications', ['user_id' => $employee->id, 'type' => 'ews.kgb']);
        $this->assertDatabaseHas('notifications', ['user_id' => $adminEmployee->id, 'type' => 'ews.kgb']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $superEmployee->id, 'type' => 'ews.kgb']);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 2);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $adminEmployee->id);
        Queue::assertNotPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $superEmployee->id);
    }

    public function test_ews_satyalancana_mengirim_in_app_dan_email_hanya_ke_pegawai_dan_admin_kepegawaian(): void
    {
        Queue::fake();
        $this->enableEventChannels('ews.satyalancana');
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $adminEmployee = Employee::factory()->create(['email' => 'admin@example.test']);
        $superEmployee = Employee::factory()->create(['email' => 'super@example.test']);
        User::factory()->adminKepegawaian()->create(['employee_id' => $adminEmployee->id]);
        User::factory()->superAdmin()->create(['employee_id' => $superEmployee->id]);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'ews.satyalancana',
            title: 'Peringatan EWS: Satyalancana',
            body: 'Milestone Satyalancana mendekat.',
            data: ['ews_alert_id' => 'alert-satya-1'],
        );

        $this->assertDatabaseHas('notifications', ['user_id' => $employee->id, 'type' => 'ews.satyalancana']);
        $this->assertDatabaseHas('notifications', ['user_id' => $adminEmployee->id, 'type' => 'ews.satyalancana']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $superEmployee->id, 'type' => 'ews.satyalancana']);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 2);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $adminEmployee->id);
        Queue::assertNotPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $superEmployee->id);
    }

    public function test_scheduler_failure_mengirim_in_app_hanya_ke_super_admin_yang_diberikan_sebagai_penerima_utama(): void
    {
        Queue::fake();
        $this->seed(ReferenceSeeder::class);
        $superEmployee = Employee::factory()->create(['email' => 'super@example.test']);
        User::factory()->superAdmin()->create(['employee_id' => $superEmployee->id]);

        $notification = app(NotificationService::class)->createForEmployee(
            employee: $superEmployee,
            type: 'ews.scheduler_failed',
            title: 'Gagal Eksekusi Scheduler EWS',
            body: 'Scheduler EWS harian gagal berjalan.',
        );

        $this->assertNotNull($notification);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $superEmployee->id,
            'type' => 'ews.scheduler_failed',
        ]);
        Queue::assertNotPushed(SendSimpegNotificationEmailJob::class);
    }

    public function test_cuti_tetap_hanya_mengirim_in_app_dan_email_ke_penerima_utama(): void
    {
        Queue::fake();
        $this->enableEventChannels('cuti.pengajuan_baru');
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $adminEmployee = Employee::factory()->create(['email' => 'admin@example.test']);
        $superEmployee = Employee::factory()->create(['email' => 'super@example.test']);
        User::factory()->adminKepegawaian()->create(['employee_id' => $adminEmployee->id]);
        User::factory()->superAdmin()->create(['employee_id' => $superEmployee->id]);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'cuti.pengajuan_baru',
            title: 'Pengajuan Cuti Menunggu Persetujuan',
            body: 'Pegawai mengajukan cuti.',
        );

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $employee->id, 'type' => 'cuti.pengajuan_baru']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $adminEmployee->id, 'type' => 'cuti.pengajuan_baru']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $superEmployee->id, 'type' => 'cuti.pengajuan_baru']);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id);
    }

    public function test_cuti_submission_queues_email_to_primary_recipient(): void
    {
        Queue::fake();
        $this->enableEventChannels('cuti.pengajuan_baru');
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
        $this->enableEventChannels('cuti.pengajuan_baru');
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
        $this->enableEventChannels('cuti.pengajuan_baru');
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

    public function test_cuti_cancellation_request_queues_email_to_primary_recipient(): void
    {
        $queue = Queue::fake();
        Mail::fake();
        $this->enableEventChannels('cuti.pembatalan_diajukan');
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $leave = $this->makeLeaveRequestWithSteps(Employee::factory()->create(), [$employee]);
        $requester = User::factory()->create(['employee_id' => $leave->employee_id]);
        $admin = User::factory()->adminKepegawaian()->create(['employee_id' => $employee->id]);
        $leave->update(['status' => LeaveRequest::STATUS_CANCELLATION_PENDING]);
        $cancellation = LeaveCancellationRequest::create([
            'leave_request_id' => $leave->id,
            'requested_by' => $requester->id,
            'reason' => 'Jadwal berubah.',
            'status' => LeaveCancellationRequest::STATUS_PENDING,
            'resume_status' => 'menunggu_approval',
        ]);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'cuti.pembatalan_diajukan',
            title: 'Permohonan Pembatalan Cuti Baru',
            body: 'Terdapat permohonan pembatalan cuti yang menunggu keputusan Anda.',
            data: [
                'url' => route('cuti.cancellations.index', [], false),
                'leave_request_id' => $leave->id,
                'leave_cancellation_request_id' => $cancellation->id,
            ],
        );

        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
        Queue::assertPushed(
            SendSimpegNotificationEmailJob::class,
            fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id
        );

        $job = $queue->pushed(SendSimpegNotificationEmailJob::class)->sole();
        app()->call([$job, 'handle']);
        Mail::assertSent(SimpegNotificationMail::class, fn (SimpegNotificationMail $mail): bool => $mail->hasTo('pegawai@example.test'));

        $cancellation->update(['status' => LeaveCancellationRequest::STATUS_REJECTED, 'decided_by' => $admin->id, 'decided_at' => now()]);
        $leave->update(['status' => 'menunggu_approval']);
        Mail::fake();
        app()->call([$job, 'handle']);
        Mail::assertNothingSent();
    }

    public function test_cuti_decline_queues_email_to_primary_recipient(): void
    {
        Queue::fake();
        $this->enableEventChannels('cuti.tidak_disetujui');
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

    public function test_ews_email_goes_to_employee_and_admin_kepegawaian_but_not_super_admin(): void
    {
        Queue::fake();
        $this->enableEventChannels('ews.kgb');
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

        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 2);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $adminEmployee->id);
        Queue::assertNotPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $superEmployee->id);
        Queue::assertNotPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $pimpinanEmployee->id);
    }

    public function test_ews_email_recipients_are_deduplicated(): void
    {
        Queue::fake();
        $this->enableEventChannels('ews.kgb');
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        User::factory()->adminKepegawaian()->create(['employee_id' => $employee->id]);

        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'ews.kgb',
            title: 'Peringatan EWS: KGB',
            body: 'Jadwal KGB mendekat.',
            data: ['ews_alert_id' => 'alert-1'],
        );

        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 1);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id);
    }

    public function test_non_eligible_promotion_does_not_queue_email_until_admin_policy_is_decided(): void
    {
        Queue::fake();
        $this->enableEventChannels('ews.kenaikan_pangkat');
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
        $job = new SendSimpegNotificationEmailJob('employee-id', 'cuti.disetujui', 'Judul', 'Isi pesan');

        $this->assertSame(3, $job->tries);
    }

    public function test_job_sends_mail_to_employee_email(): void
    {
        Mail::fake();
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $leave = $this->makeApprovedLeaveRequest($employee);
        $this->setEventChannelPolicy('cuti.disetujui', 'email', true);
        $job = new SendSimpegNotificationEmailJob($employee->id, 'cuti.disetujui', 'Judul Notifikasi', 'Isi notifikasi', [
            'leave_request_id' => $leave->id, 'url' => '/dashboard',
        ]);

        app()->call([$job, 'handle']);

        Mail::assertSent(SimpegNotificationMail::class, function (SimpegNotificationMail $mail): bool {
            return $mail->hasTo('pegawai@example.test') && $mail->title === 'Judul Notifikasi';
        });
    }

    public function test_email_persetujuan_tanpa_pengajuan_yang_dapat_diverifikasi_dilewati(): void
    {
        Mail::fake();
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $this->setEventChannelPolicy('cuti.disetujui', 'email', true);

        foreach ([[], ['leave_request_id' => '00000000-0000-4000-8000-000000000001']] as $data) {
            $job = new SendSimpegNotificationEmailJob($employee->id, 'cuti.disetujui', 'Pengajuan Cuti Disetujui', 'Cuti telah disetujui.', $data);
            app()->call([$job, 'handle']);
        }

        Mail::assertNothingSent();
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
        $leave = $this->makeApprovedLeaveRequest($employee);
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP gagal dengan password rahasia-test'));

        $job = new SendSimpegNotificationEmailJob(
            $employee->id,
            'cuti.disetujui',
            'Pengajuan Cuti Disetujui',
            'Pengajuan cuti Anda telah disetujui sepenuhnya.',
            ['leave_request_id' => $leave->id],
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
        $this->enableEventChannels('cuti.disetujui');
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $pybmc = Employee::factory()->create();
        $pybmcUser = User::factory()->pimpinan()->create(['employee_id' => $pybmc->id]);
        $leave = $this->makeLeaveRequestWithSteps($employee, [$pybmc]);

        app(ApproveLeaveAction::class)->execute(
            $leave,
            $pybmc,
            $leave->steps()->where('status', 'active')->valueOrFail('id'),
            (int) $leave->fresh()->revision_version,
            null,
            $this->actorRequest($pybmcUser),
        );

        $this->assertSame('disetujui', $leave->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'cuti.disetujui',
        ]);
        Queue::assertPushed(SendSimpegNotificationEmailJob::class);
    }

    public function test_job_queued_dilewati_bila_policy_email_dimatikan_sebelum_handle(): void
    {
        Queue::fake();
        Mail::fake();
        $this->enableEventChannels('cuti.disetujui');
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);

        $leave = $this->makeApprovedLeaveRequest($employee);
        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'cuti.disetujui',
            title: 'Pengajuan Cuti Disetujui',
            body: 'Pengajuan cuti telah disetujui.',
            data: ['leave_request_id' => $leave->id],
        );

        $queuedJob = null;
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, function (SendSimpegNotificationEmailJob $job) use (&$queuedJob): bool {
            $queuedJob = $job;

            return true;
        });
        $this->setEventChannelPolicy('cuti.disetujui', 'email', false);

        $this->assertInstanceOf(SendSimpegNotificationEmailJob::class, $queuedJob);
        app()->call([$queuedJob, 'handle']);

        Mail::assertNothingSent();
    }

    public function test_job_queued_dilewati_bila_master_email_dimatikan_sebelum_handle(): void
    {
        Queue::fake();
        Mail::fake();
        $this->enableEventChannels('cuti.disetujui');
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);

        $leave = $this->makeApprovedLeaveRequest($employee);
        app(NotificationService::class)->createForEmployee(
            employee: $employee,
            type: 'cuti.disetujui',
            title: 'Pengajuan Cuti Disetujui',
            body: 'Pengajuan cuti telah disetujui.',
            data: ['leave_request_id' => $leave->id],
        );

        $queuedJob = null;
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, function (SendSimpegNotificationEmailJob $job) use (&$queuedJob): bool {
            $queuedJob = $job;

            return true;
        });
        RefNotificationChannel::query()->where('code', 'email')->update(['is_enabled' => false]);

        $this->assertInstanceOf(SendSimpegNotificationEmailJob::class, $queuedJob);
        app()->call([$queuedJob, 'handle']);

        Mail::assertNothingSent();
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
        $this->enableEventChannels('cuti.tidak_disetujui');
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $approver = Employee::factory()->create();
        $leave = $this->makeLeaveRequestWithSteps($employee, [$approver]);

        app(DeclineLeaveAction::class)->execute(
            $leave,
            $approver,
            $leave->steps()->where('status', 'active')->valueOrFail('id'),
            (int) $leave->fresh()->revision_version,
            'Dokumen pendukung tidak sesuai.',
            Request::create('/'),
        );

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
        $queue = Queue::fake();
        Mail::fake();
        $this->enableEventChannels('cuti.menunggu_persetujuan');
        $employee = Employee::factory()->create();
        $kepalaBagian = Employee::factory()->create(['email' => 'kabag@example.test']);
        $kepalaBagianUser = User::factory()->kepalaBagian()->create(['employee_id' => $kepalaBagian->id]);
        $pybmc = Employee::factory()->create(['email' => 'pybmc@example.test']);
        $leave = $this->makeLeaveRequestWithSteps($employee, [$kepalaBagian, $pybmc]);

        app(ApproveLeaveAction::class)->execute(
            $leave,
            $kepalaBagian,
            $leave->steps()->where('status', 'active')->valueOrFail('id'),
            (int) $leave->fresh()->revision_version,
            null,
            $this->actorRequest($kepalaBagianUser),
        );

        $notification = SimpegNotification::query()
            ->where('user_id', $pybmc->id)
            ->where('type', 'cuti.menunggu_persetujuan')
            ->firstOrFail();

        // Approver tahap berikutnya diarahkan ke antrean approval lewat path internal relatif tanpa parameter.
        $this->assertSame($leave->id, $notification->data['leave_request_id']);
        $this->assertSame((string) $leave->fresh()->revision_version, $notification->data['leave_request_version']);
        $this->assertSame('/cuti/approval', $notification->data['url']);

        $job = $queue->pushed(SendSimpegNotificationEmailJob::class)->sole();
        app()->call([$job, 'handle']);
        Mail::assertSent(SimpegNotificationMail::class, fn (SimpegNotificationMail $mail): bool => $mail->hasTo('pybmc@example.test'));
    }

    public function test_email_approval_yang_sudah_antre_dilewati_saat_pengajuan_ditahan_pembatalan(): void
    {
        Mail::fake();
        $this->enableEventChannels('cuti.pengajuan_baru');
        $approver = Employee::factory()->create(['email' => 'approver@example.test']);
        $leave = $this->makeLeaveRequestWithSteps(Employee::factory()->create(), [$approver]);
        $job = new SendSimpegNotificationEmailJob($approver->id, 'cuti.pengajuan_baru', 'Pengajuan Cuti Baru', 'Ada pengajuan cuti.', [
            'leave_request_id' => $leave->id,
            'leave_request_step_id' => $leave->steps()->where('status', 'active')->valueOrFail('id'),
            'leave_request_version' => (string) $leave->fresh()->revision_version,
        ]);

        $leave->update(['status' => LeaveRequest::STATUS_CANCELLATION_PENDING]);
        app()->call([$job, 'handle']);

        Mail::assertNothingSent();
    }

    public function test_email_approval_hanya_mengirim_versi_pengajuan_terbaru(): void
    {
        Mail::fake();
        $this->enableEventChannels('cuti.pengajuan_baru');
        $approver = Employee::factory()->create(['email' => 'approver@example.test']);
        $leave = $this->makeLeaveRequestWithSteps(Employee::factory()->create(), [$approver]);
        $data = [
            'leave_request_id' => $leave->id,
            'leave_request_step_id' => $leave->steps()->where('status', 'active')->valueOrFail('id'),
            'leave_request_version' => '1',
        ];
        $oldJob = new SendSimpegNotificationEmailJob($approver->id, 'cuti.pengajuan_baru', 'Pengajuan Awal', 'Ada pengajuan cuti.', $data);

        $leave->forceFill(['revision_version' => 2])->save();
        app()->call([$oldJob, 'handle']);
        Mail::assertNothingSent();

        $currentJob = new SendSimpegNotificationEmailJob($approver->id, 'cuti.pengajuan_baru', 'Pengajuan Diperbarui', 'Ada revisi cuti.', [...$data, 'leave_request_version' => '2']);
        app()->call([$currentJob, 'handle']);
        Mail::assertSent(SimpegNotificationMail::class, fn (SimpegNotificationMail $mail): bool => $mail->hasTo('approver@example.test'));
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

    /** Menyiapkan keputusan final agar test delivery tidak bergantung pada konteks pengajuan yang hilang. */
    private function makeApprovedLeaveRequest(Employee $employee): LeaveRequest
    {
        $leave = $this->makeLeaveRequestWithSteps($employee, [Employee::factory()->create()]);
        $leave->steps()->update(['status' => 'approved', 'acted_at' => now()]);
        $leave->update(['status' => 'disetujui']);

        return $leave;
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

    public function test_upsert_ews_reminder_diblokir_saat_kebijakan_event_in_app_nonaktif(): void
    {
        Queue::fake();
        // Channel in_app global tetap aktif; hanya kebijakan event ews.kgb yang mati.
        // Reminder harus fail-closed per event, bukan hanya mengikuti channel global.
        $this->setEventChannelPolicy('ews.kgb', 'in_app', false);
        $this->setEventChannelPolicy('ews.kgb', 'email', true);
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $alert = $this->activeEwsAlertFor($employee);

        $notification = app(NotificationService::class)->upsertEwsReminder(
            $employee,
            $alert,
            'ews.kgb',
            'Pengingat KGB',
            'KGB berikutnya sudah dekat.',
            ['ews_alert_id' => $alert->id],
        );

        $this->assertNull($notification);
        $this->assertDatabaseMissing('notifications', ['user_id' => $employee->id]);
        Queue::assertNotPushed(SendSimpegNotificationEmailJob::class);
    }

    public function test_upsert_ews_reminder_fan_out_email_ke_admin_kepegawaian_saat_pertama_dibuat(): void
    {
        Queue::fake();
        $this->setEventChannelPolicy('ews.kgb', 'in_app', true);
        $this->setEventChannelPolicy('ews.kgb', 'email', true);
        $employee = Employee::factory()->create(['email' => 'pegawai@example.test']);
        $adminEmployee = Employee::factory()->create(['email' => 'admin@example.test']);
        User::factory()->create([
            'role' => 'admin_kepegawaian',
            'employee_id' => $adminEmployee->id,
        ]);
        $alert = $this->activeEwsAlertFor($employee);

        app(NotificationService::class)->upsertEwsReminder(
            $employee,
            $alert,
            'ews.kgb',
            'Pengingat KGB',
            'KGB berikutnya sudah dekat.',
            ['ews_alert_id' => $alert->id],
        );

        // Fan-out reminder mengikuti alur createForEmployee: pegawai dan Admin
        // Kepegawaian sama-sama menerima email saat reminder pertama kali dibuat.
        Queue::assertPushed(SendSimpegNotificationEmailJob::class, 2);
    }

    private function activeEwsAlertFor(Employee $employee): EwsAlert
    {
        return EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_processed' => false,
        ]);
    }

    private function actorRequest(User $actor): Request
    {
        $request = Request::create('/cuti/approval', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }

    /**
     * Menetapkan satu pasangan event-channel agar test mengunci kebijakan delivery dua lapis berbasis DB.
     */
    private function setEventChannelPolicy(string $eventKey, string $channelCode, bool $isEnabled): void
    {
        $channel = RefNotificationChannel::query()->where('code', $channelCode)->firstOrFail();

        NotificationEventChannel::query()->updateOrCreate([
            'event_key' => $eventKey,
            'notification_channel_id' => $channel->id,
        ], [
            'is_enabled' => $isEnabled,
        ]);
    }

    /**
     * Menghapus pasangan tertentu agar test dapat membuktikan perilaku fail-closed saat kebijakan hilang.
     */
    private function deleteEventChannelPolicy(string $eventKey, string $channelCode): void
    {
        $channelId = RefNotificationChannel::query()->where('code', $channelCode)->value('id');

        NotificationEventChannel::query()
            ->where('event_key', $eventKey)
            ->where('notification_channel_id', $channelId)
            ->delete();
    }

    /**
     * Mengaktifkan dua channel efektif yang diwajibkan untuk event cuti dan EWS yang didukung.
     */
    private function enableEventChannels(string $eventKey): void
    {
        $this->setEventChannelPolicy($eventKey, 'in_app', true);
        $this->setEventChannelPolicy($eventKey, 'email', true);
    }
}
