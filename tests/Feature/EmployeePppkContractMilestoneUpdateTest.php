<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\EwsAlert;
use App\Models\RefJenisPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Employees\TmtCalculatorService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeePppkContractMilestoneUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    /**
     * Test: Mengubah tanggal_akhir_kontrak memicu invalidasi milestone PPPK.
     *
     * Issue: UpdateEmployeeAction expire alert lama tapi TIDAK sync milestone
     * saat tanggal_akhir_kontrak berubah. Scheduler kemudian menggunakan
     * milestone lama dan membuat alert baru dengan tanggal yang salah.
     */
    public function test_updating_tanggal_akhir_kontrak_syncs_pppk_milestone(): void
    {

        $user = User::factory()->create(['role' => 'super_admin']);

        $jenisPegawai = RefJenisPegawai::firstOrCreate(
            ['nama' => 'PPPK'],
            ['kode' => 'PPPK']
        );

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'jenis_pegawai_id' => $jenisPegawai->id,
            'tanggal_akhir_kontrak' => '2027-06-30', // ← Contract end date
        ]);

        // Create PPPK appointment
        $employee->appointments()->create([
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => '2023-07-01',
            'no_sk' => 'SK-PPPK-001',
            'tanggal_sk' => '2023-06-15',
        ]);

        // Initial sync to create milestone
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $oldMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pppk_contract_end')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($oldMilestone);
        $this->assertEquals('2027-06-30', $oldMilestone->milestone_date->toDateString());
        $oldMilestoneId = $oldMilestone->id;

        // Create active EWS alert for old contract date
        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KONTRAK_PPPK',
            'target_date' => '2027-06-30',
            'interval_days' => 180,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
            'is_processed' => false,
        ]);

        SimpegNotification::create([
            'user_id' => $employee->id,
            'ews_alert_id' => $alert->id,
            'type' => 'ews.kontrak_pppk',
            'title' => 'Test Alert',
            'body' => 'Test body',
            'is_read' => false,
        ]);

        // Update tanggal_akhir_kontrak via UpdateEmployeeAction
        $response = $this->actingAs($user)->post(route('pegawai.update', $employee->id), [
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'email' => $employee->email,
            'tanggal_akhir_kontrak' => '2028-12-31', // ← Changed!
        ]);

        $response->assertRedirect();

        // Verify: Old milestone invalidated
        $oldMilestone->refresh();
        $this->assertFalse($oldMilestone->is_active, 'Old PPPK milestone should be invalidated');

        // Verify: New milestone created with updated date
        $newMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pppk_contract_end')
            ->where('is_active', true)
            ->where('id', '!=', $oldMilestoneId)
            ->first();

        $this->assertNotNull($newMilestone, 'New PPPK milestone should be created');
        $this->assertEquals('2028-12-31', $newMilestone->milestone_date->toDateString());

        // Verify: Old alert expired (existing behavior)
        $alert->refresh();
        $this->assertEquals(EwsAlert::FOLLOWUP_STATUS_EXPIRED, $alert->followup_status);
        $this->assertTrue($alert->is_processed);

        // Verify: Old notification marked as read (existing behavior)
        $notification = SimpegNotification::where('ews_alert_id', $alert->id)->first();
        $this->assertTrue($notification->is_read);
        $this->assertNotNull($notification->read_at);
    }

    /**
     * Test: Scheduler menggunakan milestone terbaru setelah contract update.
     */
    public function test_scheduler_uses_updated_pppk_milestone_not_stale_one(): void
    {

        $user = User::factory()->create(['role' => 'super_admin']);

        $jenisPegawai = RefJenisPegawai::firstOrCreate(
            ['nama' => 'PPPK'],
            ['kode' => 'PPPK']
        );

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'jenis_pegawai_id' => $jenisPegawai->id,
            'tanggal_akhir_kontrak' => now()->addMonths(6)->toDateString(),
        ]);

        $employee->appointments()->create([
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => now()->subYears(2)->toDateString(),
            'no_sk' => 'SK-PPPK-001',
            'tanggal_sk' => now()->subYears(2)->toDateString(),
        ]);

        // Initial sync
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Update to further future
        $newContractDate = now()->addYears(2)->toDateString();
        $this->actingAs($user)->post(route('pegawai.update', $employee->id), [
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'email' => $employee->email,
            'tanggal_akhir_kontrak' => $newContractDate,
        ]);

        // Verify: Only one active PPPK milestone
        $activeMilestones = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pppk_contract_end')
            ->where('is_active', true)
            ->get();

        $this->assertCount(1, $activeMilestones, 'Should have exactly one active PPPK milestone');
        $this->assertEquals($newContractDate, $activeMilestones->first()->milestone_date->toDateString());
    }

    /**
     * Test: Scheduler tidak membuat alert ulang dengan tanggal lama setelah update.
     *
     * Scenario: Bug sebelum fix - milestone lama tetap aktif, scheduler membuat
     * alert baru dengan tanggal lama meskipun alert sudah di-expire.
     */
    public function test_scheduler_does_not_recreate_alert_with_old_contract_date(): void
    {

        $user = User::factory()->create(['role' => 'super_admin']);

        $jenisPegawai = RefJenisPegawai::firstOrCreate(
            ['nama' => 'PPPK'],
            ['kode' => 'PPPK']
        );

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'jenis_pegawai_id' => $jenisPegawai->id,
            'tanggal_akhir_kontrak' => '2027-06-30',
        ]);

        $employee->appointments()->create([
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => now()->subYears(2)->toDateString(),
            'no_sk' => 'SK-PPPK-001',
            'tanggal_sk' => now()->subYears(2)->toDateString(),
        ]);

        // Initial sync
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Update contract date
        $response = $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->post(route('pegawai.update', $employee->id), [
                'nama_lengkap' => $employee->nama_lengkap,
                'nip' => $employee->nip,
                'email' => $employee->email,
                'tanggal_akhir_kontrak' => '2028-12-31',
            ], ['X-CSRF-TOKEN' => 'test-token']);

        // Ensure the update succeeded (no validation/server errors)
        $response->assertRedirect(route('data-pegawai'));

        // Simulate scheduler running (would use milestone)
        $activeMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pppk_contract_end')
            ->where('is_active', true)
            ->first();

        // Assert: Active milestone uses NEW date, not old date
        $this->assertNotNull($activeMilestone);
        $this->assertEquals('2028-12-31', $activeMilestone->milestone_date->toDateString());
        $this->assertNotEquals('2027-06-30', $activeMilestone->milestone_date->toDateString(), 'Active milestone should NOT use old contract date');

    }

    /**
     * Test: Non-PPPK employee tidak terpengaruh oleh perubahan tanggal_akhir_kontrak.
     */
    public function test_non_pppk_employee_contract_change_does_not_affect_milestones(): void
    {

        $user = User::factory()->create(['role' => 'super_admin']);

        $jenisPegawai = RefJenisPegawai::firstOrCreate(
            ['nama' => 'PNS'],
            ['kode' => 'PNS']
        );

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'jenis_pegawai_id' => $jenisPegawai->id,
            'tanggal_akhir_kontrak' => null, // ← PNS doesn't have contract
        ]);

        // Initial sync - no PPPK milestone should be created
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $pppkMilestones = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pppk_contract_end')
            ->count();

        $this->assertEquals(0, $pppkMilestones, 'PNS employee should not have PPPK milestone');

        // Update employee (shouldn't create PPPK milestone)
        $this->actingAs($user)->post(route('pegawai.update', $employee->id), [
            'nama_lengkap' => 'Updated Name',
            'nip' => $employee->nip,
            'email' => $employee->email,
        ]);

        $pppkMilestonesAfter = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pppk_contract_end')
            ->count();

        $this->assertEquals(0, $pppkMilestonesAfter, 'PNS employee still should not have PPPK milestone');
    }
}
