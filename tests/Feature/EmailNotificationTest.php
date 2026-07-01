<?php

namespace Tests\Feature;

use App\Jobs\SendSimpegNotificationEmailJob;
use App\Mail\SimpegNotificationMail;
use App\Models\Employee;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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
}
