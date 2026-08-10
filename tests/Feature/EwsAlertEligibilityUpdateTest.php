<?php

namespace Tests\Feature;

use App\Models\DisciplineRecord;
use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\EwsAlert;
use App\Models\SimpegNotification;
use App\Services\EwsEngineService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EwsAlertEligibilityUpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Alert promosi yang sudah ada harus memperbarui is_eligible ketika kondisi pegawai berubah.
     *
     * Issue: Jika alert KENAIKAN_PANGKAT dibuat saat pegawai eligible (is_kinerja_baik=true, no discipline),
     * lalu kinerja menjadi buruk atau hukuman disiplin diaktifkan, scheduler menemukan alert yang sudah ada
     * dan langsung return tanpa memperbarui is_eligible. Record tetap menunjukkan true meskipun evaluasi
     * terbaru adalah false, dan notifikasi lama yang belum dibaca tetap terlihat dengan status eligible.
     */
    public function test_alert_updates_is_eligible_when_employee_becomes_ineligible(): void
    {
        $this->seed(ReferenceSeeder::class);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'is_kinerja_baik' => true, // ← Initially eligible
        ]);

        // Create milestone for rank promotion
        $targetDate = now()->addDays(90);
        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => 'kenaikan_pangkat',
            'milestone_date' => $targetDate,
            'is_active' => true,
            'metadata' => ['required_years' => 4],
        ]);

        // First scheduler run: Creates alert with is_eligible=true
        app(EwsEngineService::class)->run();

        $alert = EwsAlert::where('employee_id', $employee->id)
            ->where('type', 'KENAIKAN_PANGKAT')
            ->first();

        $this->assertNotNull($alert, 'Alert should be created on first run');
        $this->assertTrue($alert->is_eligible, 'Alert should be eligible initially');
        $this->assertNotNull($alert->notified_at, 'Notification should be sent for eligible alert');

        $notification = SimpegNotification::where('ews_alert_id', $alert->id)->first();
        $this->assertNotNull($notification, 'Notification should exist');
        $this->assertFalse($notification->is_read, 'Notification should be unread');

        // Employee performance becomes poor
        $employee->update(['is_kinerja_baik' => false]);

        // Second scheduler run: Should update is_eligible to false
        app(EwsEngineService::class)->run();

        $alert->refresh();
        $this->assertFalse($alert->is_eligible, 'Alert should be updated to ineligible after performance drop');

        // Verify notification is updated with new eligibility status
        $notification->refresh();
        $this->assertEquals(false, $notification->data['is_eligible'] ?? null, 'Notification data should reflect ineligibility');
    }

    /**
     * Test: Alert promosi diperbarui ketika hukuman disiplin ditambahkan.
     */
    public function test_alert_updates_is_eligible_when_discipline_record_added(): void
    {
        $this->seed(ReferenceSeeder::class);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'is_kinerja_baik' => true,
        ]);

        // Create milestone
        $targetDate = now()->addDays(90);
        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => 'kenaikan_pangkat',
            'milestone_date' => $targetDate,
            'is_active' => true,
            'metadata' => ['required_years' => 4],
        ]);

        // First run: eligible
        app(EwsEngineService::class)->run();

        $alert = EwsAlert::where('employee_id', $employee->id)
            ->where('type', 'KENAIKAN_PANGKAT')
            ->first();

        $this->assertTrue($alert->is_eligible);

        // Add active discipline record
        DisciplineRecord::create([
            'employee_id' => $employee->id,
            'jenis_hukuman' => 'Ringan',
            'tanggal_mulai' => now()->subDays(10),
            'tanggal_selesai' => now()->addDays(30),
            'no_sk' => 'SK-DISIPLIN-001',
            'is_active' => true,
        ]);

        // Second run: should become ineligible
        app(EwsEngineService::class)->run();

        $alert->refresh();
        $this->assertFalse($alert->is_eligible, 'Alert should be ineligible when active discipline record exists');
    }

    /**
     * Test: Alert promosi diperbarui kembali menjadi eligible ketika kondisi membaik.
     */
    public function test_alert_updates_back_to_eligible_when_conditions_improve(): void
    {
        $this->seed(ReferenceSeeder::class);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'is_kinerja_baik' => false, // ← Initially ineligible
        ]);

        // Create milestone
        $targetDate = now()->addDays(90);
        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => 'kenaikan_pangkat',
            'milestone_date' => $targetDate,
            'is_active' => true,
            'metadata' => ['required_years' => 4],
        ]);

        // First run: creates alert with is_eligible=false
        app(EwsEngineService::class)->run();

        $alert = EwsAlert::where('employee_id', $employee->id)
            ->where('type', 'KENAIKAN_PANGKAT')
            ->first();

        $this->assertFalse($alert->is_eligible);
        $this->assertNull($alert->notified_at, 'No notification should be sent for ineligible alert');

        // Performance improves
        $employee->update(['is_kinerja_baik' => true]);

        // Second run: should update to eligible and send notification
        app(EwsEngineService::class)->run();

        $alert->refresh();
        $this->assertTrue($alert->is_eligible, 'Alert should be updated to eligible after performance improves');
        $this->assertNotNull($alert->notified_at, 'Notification should be sent after becoming eligible');

        $notification = SimpegNotification::where('ews_alert_id', $alert->id)->first();
        $this->assertNotNull($notification, 'Notification should be created after becoming eligible');
    }

    /**
     * Test: Satyalancana alert juga memperbarui eligibilitas berdasarkan flag manual.
     */
    public function test_satyalancana_alert_updates_eligibility_based_on_flag(): void
    {
        $this->seed(ReferenceSeeder::class);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'is_satyalancana_eligible' => true, // ← Initially eligible
        ]);

        // Create milestone for satyalancana 10 years
        $targetDate = now()->addDays(180);
        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => 'satyalancana',
            'milestone_date' => $targetDate,
            'is_active' => true,
            'metadata' => ['satyalancana_years' => 10],
        ]);

        // First run: eligible
        app(EwsEngineService::class)->run();

        $alert = EwsAlert::where('employee_id', $employee->id)
            ->where('type', 'SATYALANCANA')
            ->first();

        $this->assertTrue($alert->is_eligible);
        $this->assertNotNull($alert->notified_at, 'Satyalancana always sends notification');

        // Flag changed to ineligible
        $employee->update(['is_satyalancana_eligible' => false]);

        // Second run: should update to ineligible
        app(EwsEngineService::class)->run();

        $alert->refresh();
        $this->assertFalse($alert->is_eligible, 'Satyalancana alert should update eligibility based on flag');
    }

    /**
     * Test: KGB alert tidak memiliki eligibility check (is_eligible tetap null).
     */
    public function test_kgb_alert_does_not_have_eligibility_updates(): void
    {
        $this->seed(ReferenceSeeder::class);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'is_kinerja_baik' => false, // ← KGB tidak peduli kinerja
        ]);

        // Create milestone for KGB
        $targetDate = now()->addDays(60);
        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => 'kgb',
            'milestone_date' => $targetDate,
            'is_active' => true,
            'metadata' => ['required_years' => 2],
        ]);

        // Run scheduler
        app(EwsEngineService::class)->run();

        $alert = EwsAlert::where('employee_id', $employee->id)
            ->where('type', 'KGB')
            ->first();

        $this->assertNull($alert->is_eligible, 'KGB alert should have null is_eligible');
        $this->assertNotNull($alert->notified_at, 'KGB notification should always be sent');

        // Change performance (should not affect KGB)
        $employee->update(['is_kinerja_baik' => true]);

        // Second run
        app(EwsEngineService::class)->run();

        $alert->refresh();
        $this->assertNull($alert->is_eligible, 'KGB alert should remain null regardless of performance');
    }
}
