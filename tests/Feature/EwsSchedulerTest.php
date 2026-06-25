<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\EwsSchedulerRun;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\EwsEngineService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EwsSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_scheduler_creates_runs_log_successfully(): void
    {
        $this->assertSame(0, EwsSchedulerRun::count());

        // Run scheduler scan
        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsSchedulerRun::count());
        $run = EwsSchedulerRun::first();
        $this->assertSame('berhasil', $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, $run->alerts_created);
    }

    public function test_scheduler_checks_promotion_and_kgb_triggers(): void
    {
        // 1. Kenaikan Pangkat H-90 (Pangkat Tahap 1)
        $employee1 = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => now()->addDays(90)->toDateString(),
            'is_kinerja_baik' => true,
        ]);

        // 2. KGB H-60 (KGB Tahap 1)
        $employee2 = Employee::factory()->create([
            'tanggal_kgb_berikutnya' => now()->addDays(60)->toDateString(),
        ]);

        app(EwsEngineService::class)->run();

        // Should create 2 alerts
        $this->assertSame(2, EwsAlert::count());
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee1->id,
            'type' => 'KENAIKAN_PANGKAT',
            'interval_days' => 90,
        ]);
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee2->id,
            'type' => 'KGB',
            'interval_days' => 60,
        ]);

        // Verify notifications
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee1->id,
            'type' => 'ews.kenaikan_pangkat',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee2->id,
            'type' => 'ews.kgb',
        ]);
    }

    public function test_promotion_eligibility_blocks_notification_when_performance_is_poor(): void
    {
        // Kenaikan Pangkat H-90, poor performance (is_kinerja_baik = false)
        $employee = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => now()->addDays(90)->toDateString(),
            'is_kinerja_baik' => false,
        ]);

        app(EwsEngineService::class)->run();

        // Alert is created but NO notified_at or notification record is sent to employee
        $this->assertSame(1, EwsAlert::count());
        $alert = EwsAlert::first();
        $this->assertNull($alert->notified_at);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.kenaikan_pangkat',
        ]);
    }

    public function test_promotion_eligibility_blocks_notification_when_disciplinary_record_is_active(): void
    {
        // Kenaikan Pangkat H-90, kinerja baik, but active disciplinary record
        $employee = Employee::factory()->create([
            'tanggal_kenaikan_pangkat_berikutnya' => now()->addDays(90)->toDateString(),
            'is_kinerja_baik' => true,
        ]);

        DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Sedang',
            'deskripsi' => 'Melanggar disiplin jam kerja',
            'tanggal_mulai' => now()->subDay()->toDateString(),
            'no_sk' => 'SK-DISC-001',
            'tanggal_sk' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        app(EwsEngineService::class)->run();

        // Alert is created but no notification is sent
        $this->assertSame(1, EwsAlert::count());
        $alert = EwsAlert::first();
        $this->assertNull($alert->notified_at);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.kenaikan_pangkat',
        ]);
    }

    public function test_scheduler_checks_pensiun_trigger(): void
    {
        // Pensiun H-365 (Tahap 1)
        $employee = Employee::factory()->create([
            'tanggal_pensiun' => now()->addDays(365)->toDateString(),
        ]);

        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::count());
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'PENSIUN',
            'interval_days' => 365,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.pensiun',
        ]);
    }

    public function test_scheduler_checks_pppk_contract_using_tanggal_akhir_kontrak(): void
    {
        $pppkJenis = RefJenisPegawai::where('nama', 'PPPK')->firstOrFail();

        // PPPK Employee with tanggal_akhir_kontrak H-180 (Tahap 1)
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $pppkJenis->id,
            'tanggal_akhir_kontrak' => now()->addDays(180)->toDateString(),
        ]);

        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::count());
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'KONTRAK_PPPK',
            'interval_days' => 180,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.kontrak_pppk',
        ]);
    }

    public function test_scheduler_checks_pppk_contract_fallback_to_appointments(): void
    {
        $pppkJenis = RefJenisPegawai::where('nama', 'PPPK')->firstOrFail();

        // PPPK Employee with null tanggal_akhir_kontrak, but PPPK appointment 5 years minus 180 days ago
        // So target date (tmt + 5 years) is exactly H-180
        $tmt = now()->subYears(5)->addDays(180)->toDateString();
        $employee = Employee::factory()->create([
            'jenis_pegawai_id' => $pppkJenis->id,
            'tanggal_akhir_kontrak' => null,
        ]);

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => $tmt,
            'no_sk' => 'SK-PPPK-FALLBACK',
            'tanggal_sk' => $tmt,
        ]);

        app(EwsEngineService::class)->run();

        $this->assertSame(1, EwsAlert::count());
        $this->assertDatabaseHas('ews_alerts', [
            'employee_id' => $employee->id,
            'type' => 'KONTRAK_PPPK',
            'interval_days' => 180,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'ews.kontrak_pppk',
        ]);
    }

    public function test_scheduler_prevents_duplicate_alerts(): void
    {
        $employee = Employee::factory()->create([
            'tanggal_kgb_berikutnya' => now()->addDays(60)->toDateString(),
        ]);

        // Run once
        app(EwsEngineService::class)->run();
        $this->assertSame(1, EwsAlert::count());

        // Run second time
        app(EwsEngineService::class)->run();
        $this->assertSame(1, EwsAlert::count()); // Still 1 due to duplicate prevention
    }

    public function test_scheduler_records_failure_and_notifies_super_admin(): void
    {
        $superAdminEmployee = Employee::factory()->create();
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'employee_id' => $superAdminEmployee->id,
        ]);

        // Mock failure by binding a service that throws exception
        $this->mock(EwsEngineService::class, function ($mock) {
            $mock->shouldReceive('run')->andThrow(new \RuntimeException('Engine error simulation'));
        });

        try {
            app(EwsEngineService::class)->run();
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('Engine error simulation', $e->getMessage());
        }

        // Resolve a real EwsEngineService but pass it a notification service mock that throws exception.
        // This will trigger the catch block in EwsEngineService, update the run status to 'gagal', and notify the Super Admin.
        $notificationMock = $this->mock(\App\Services\NotificationService::class);
        $notificationMock->shouldReceive('createForEmployee')
            ->with(\Mockery::any(), 'ews.kgb', \Mockery::any(), \Mockery::any(), \Mockery::any())
            ->once()
            ->andThrow(new \RuntimeException('Service failure simulation'));

        $notificationMock->shouldReceive('createForEmployee')
            ->with(\Mockery::any(), 'ews.scheduler_failed', \Mockery::any(), \Mockery::any())
            ->once()
            ->andReturn(new \App\Models\SimpegNotification());

        try {
            // Seed an employee to trigger notification code path
            Employee::factory()->create([
                'tanggal_kgb_berikutnya' => now()->addDays(60)->toDateString(),
            ]);

            $engine = new EwsEngineService($notificationMock);
            $engine->run();
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            // Verify run status was updated to gagal
            $this->assertSame(1, EwsSchedulerRun::count());
            $run = EwsSchedulerRun::first();
            $this->assertSame('gagal', $run->status);
            $this->assertStringContainsString('Service failure simulation', $run->error_message);
        }
    }
}
