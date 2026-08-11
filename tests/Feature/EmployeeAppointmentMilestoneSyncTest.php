<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\RefJenisPegawai;
use App\Models\User;
use App\Services\Employees\TmtCalculatorService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmployeeAppointmentMilestoneSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Memastikan perubahan TMT pengangkatan menyegarkan milestone Satyalancana setelah riwayat tersimpan. */
    public function test_appointment_tmt_change_triggers_milestone_sync_via_update_action(): void
    {
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create(['role' => 'super_admin']);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
        ]);

        // Create initial appointment with TMT 2020-01-01
        $appointment = $employee->appointment()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-001',
            'tanggal_sk' => '2019-12-15',
        ]);

        // Initial sync to create Satyalancana milestones
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Verify initial milestone exists
        $oldMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'satyalancana')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($oldMilestone, 'Initial Satyalancana milestone should exist');
        $this->assertEquals('2030-01-01', $oldMilestone->milestone_date->toDateString(), 'Should be 10 years from 2020-01-01');

        // Update appointment TMT through UpdateEmployeeAction (production flow)
        $response = $this->actingAs($user)->postWithCsrf(route('pegawai.update', $employee->id), [
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'email_pribadi' => $employee->email_pribadi,
            'pengangkatan_jenis_pengangkatan' => 'PNS',
            'pengangkatan_tmt_pengangkatan' => '2018-06-15', // ← Changed TMT
            'pengangkatan_no_sk' => 'SK-001',
            'pengangkatan_tanggal_sk' => '2018-06-01',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        // Verify: Old milestone should be invalidated
        $oldMilestone->refresh();
        $this->assertFalse($oldMilestone->is_active, 'Old Satyalancana milestone should be invalidated');

        // Verify: New milestone created with updated TMT
        $newMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'satyalancana')
            ->where('is_active', true)
            ->where('id', '!=', $oldMilestone->id)
            ->first();

        $this->assertNotNull($newMilestone, 'New Satyalancana milestone should be created');
        $this->assertEquals('2028-06-15', $newMilestone->milestone_date->toDateString(), 'Should be 10 years from new TMT 2018-06-15');
    }

    /** Memastikan perubahan TMT pengangkatan PPPK menyegarkan milestone terkait. */
    public function test_pppk_tmt_change_triggers_milestone_sync(): void
    {
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create(['role' => 'super_admin']);

        $jenisPegawai = RefJenisPegawai::firstOrCreate(
            ['nama' => 'PPPK'],
            ['kode' => 'PPPK']
        );

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'jenis_pegawai_id' => $jenisPegawai->id,
        ]);

        // Create PPPK appointment
        $employee->appointments()->create([
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => '2022-01-01',
            'no_sk' => 'SK-PPPK-001',
            'tanggal_sk' => '2021-12-15',
        ]);

        // Initial sync
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $oldMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'satyalancana')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($oldMilestone);
        $originalDate = $oldMilestone->milestone_date->toDateString();

        // Update PPPK TMT through form field
        $response = $this->actingAs($user)->postWithCsrf(route('pegawai.update', $employee->id), [
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'email_pribadi' => $employee->email_pribadi,
            'pppk_tmt_pengangkatan' => '2020-07-01', // ← Changed
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        // Verify: Milestone synced with new date
        $newMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'satyalancana')
            ->where('is_active', true)
            ->where('milestone_date', '!=', $originalDate)
            ->first();

        $this->assertNotNull($newMilestone, 'Should create new milestone with updated TMT');
    }

    /** Memastikan pengangkatan baru membentuk milestone yang sebelumnya belum memiliki sumber data. */
    public function test_new_appointment_creation_triggers_milestone_sync(): void
    {
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create(['role' => 'super_admin']);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
        ]);

        // No appointment initially, no milestone
        $milestonesBeforeCount = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'satyalancana')
            ->where('is_active', true)
            ->count();

        $this->assertEquals(0, $milestonesBeforeCount, 'Should have no Satyalancana milestone without appointment');

        // Create appointment through UpdateEmployeeAction
        $response = $this->actingAs($user)->postWithCsrf(route('pegawai.update', $employee->id), [
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'email_pribadi' => $employee->email_pribadi,
            'pengangkatan_jenis_pengangkatan' => 'PNS',
            'pengangkatan_tmt_pengangkatan' => '2015-03-01',
            'pengangkatan_no_sk' => 'SK-NEW-001',
            'pengangkatan_tanggal_sk' => '2015-02-15',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        // Verify: Milestone created after appointment
        $milestonesAfterCount = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'satyalancana')
            ->where('is_active', true)
            ->count();

        $this->assertGreaterThan(0, $milestonesAfterCount, 'Should have Satyalancana milestone after creating appointment');
    }

    /** Memastikan satu pembaruan pengangkatan merekonsiliasi semua milestone yang terkait. */
    public function test_multiple_appointment_changes_trigger_single_final_sync(): void
    {
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create(['role' => 'super_admin']);

        $jenisPegawai = RefJenisPegawai::firstOrCreate(
            ['nama' => 'PPPK'],
            ['kode' => 'PPPK']
        );

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'jenis_pegawai_id' => $jenisPegawai->id,
            'tanggal_akhir_kontrak' => '2026-12-31',
        ]);

        // Create PPPK appointment
        $employee->appointments()->create([
            'jenis_pengangkatan' => 'PPPK',
            'tmt_pengangkatan' => '2022-01-01',
            'no_sk' => 'SK-PPPK-001',
            'tanggal_sk' => '2021-12-15',
        ]);

        // Initial sync
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Track initial milestone count
        $initialCount = EmployeeMilestone::where('employee_id', $employee->id)->count();

        // Update: Change BOTH PPPK TMT AND contract end date
        $response = $this->actingAs($user)->postWithCsrf(route('pegawai.update', $employee->id), [
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'email_pribadi' => $employee->email_pribadi,
            'tanggal_akhir_kontrak' => '2027-06-30', // ← Changed contract
            'pppk_tmt_pengangkatan' => '2021-06-01', // ← Changed TMT
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        // Verify: Milestones synced (both Satyalancana and PPPK contract)
        $satyalancanaMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'satyalancana')
            ->where('is_active', true)
            ->first();

        $pppkMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pppk_contract_end')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($satyalancanaMilestone, 'Satyalancana milestone should be synced');
        $this->assertNotNull($pppkMilestone, 'PPPK contract milestone should be synced');
        $this->assertEquals('2027-06-30', $pppkMilestone->milestone_date->toDateString());
    }

    /** Memastikan perubahan yang tidak memengaruhi milestone tidak membuat catatan duplikat. */
    public function test_non_appointment_updates_dont_cause_redundant_sync(): void
    {
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create(['role' => 'super_admin']);

        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'no_hp' => '081234567890',
        ]);

        $employee->appointment()->create([
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-001',
            'tanggal_sk' => '2019-12-15',
        ]);

        // Initial sync
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $milestoneCountBefore = EmployeeMilestone::where('employee_id', $employee->id)->count();

        // Update: Only change non-milestone field (phone number)
        $response = $this->actingAs($user)->postWithCsrf(route('pegawai.update', $employee->id), [
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'email_pribadi' => $employee->email_pribadi,
            'no_hp' => '082987654321', // ← Only change phone
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        // Verify: No new milestones created (no redundant sync)
        $milestoneCountAfter = EmployeeMilestone::where('employee_id', $employee->id)->count();
        $this->assertEquals($milestoneCountBefore, $milestoneCountAfter, 'Should not create redundant milestones');
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        $token = 'employee-appointment-milestone-token';

        return $this->withSession(['_token' => $token])
            ->post($uri, [...$data, '_token' => $token]);
    }
}
