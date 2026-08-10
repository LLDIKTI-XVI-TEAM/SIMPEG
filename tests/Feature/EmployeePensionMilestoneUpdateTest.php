<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\User;
use App\Services\Employees\TmtCalculatorService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeePensionMilestoneUpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Mengubah tanggal_pensiun manual memicu sinkronisasi milestone.
     *
     * Issue: UpdateEmployeeAction hanya memanggil syncForEmployee() saat ada
     * perubahan history (rank/position/salary), tapi TIDAK saat tanggal_pensiun
     * berubah. Akibatnya milestone pensiun lama tetap aktif.
     */
    public function test_updating_tanggal_pensiun_syncs_pension_milestone(): void
    {
        $this->seed(ReferenceSeeder::class);

        $user = User::factory()->create(['role' => 'super_admin']);
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1967-03-20',
            'tanggal_pensiun' => '2032-06-15', // ← Manual pension date
        ]);

        // Initial sync to create milestone
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $oldMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pensiun')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($oldMilestone);
        $this->assertEquals('2032-06-15', $oldMilestone->milestone_date->toDateString());
        $this->assertTrue($oldMilestone->metadata['is_manual'] ?? false);
        $oldMilestoneId = $oldMilestone->id;

        // Update tanggal_pensiun via UpdateEmployeeAction (through controller)
        $response = $this->actingAs($user)->put(route('admin.pegawai.update', $employee->id), [
            'nama' => $employee->nama,
            'nip' => $employee->nip,
            'email' => $employee->email,
            'tanggal_lahir' => '1967-03-20',
            'tanggal_pensiun' => '2033-12-31', // ← Changed!
        ]);

        $response->assertRedirect();

        // Verify: Old milestone invalidated
        $oldMilestone->refresh();
        $this->assertFalse($oldMilestone->is_active, 'Old milestone should be invalidated');

        // Verify: New milestone created with updated date
        $newMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pensiun')
            ->where('is_active', true)
            ->where('id', '!=', $oldMilestoneId)
            ->first();

        $this->assertNotNull($newMilestone, 'New milestone should be created');
        $this->assertEquals('2033-12-31', $newMilestone->milestone_date->toDateString());
        $this->assertTrue($newMilestone->metadata['is_manual'] ?? false);
        $this->assertEquals('employees.tanggal_pensiun', $newMilestone->metadata['source'] ?? null);
    }

    /**
     * Test: Mengubah tanggal_lahir memicu recalculation milestone pensiun (jika belum ada manual date).
     */
    public function test_updating_tanggal_lahir_syncs_calculated_pension_milestone(): void
    {
        $this->seed(ReferenceSeeder::class);

        $user = User::factory()->create(['role' => 'super_admin']);
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1967-03-20',
            'tanggal_pensiun' => null, // ← No manual pension date
        ]);

        // Create position for BUP calculation
        $employee->positionHistories()->create([
            'jabatan_id' => 1,
            'jenis_jabatan_id' => 1,
            'nama_jabatan' => 'Test Position',
            'unit_kerja_id' => 1,
            'tmt_jabatan' => now()->subYears(5)->toDateString(),
            'no_sk' => 'SK-001',
            'tanggal_sk' => now()->subYears(5)->toDateString(),
            'is_latest' => true,
        ]);

        // Initial sync - will calculate from tanggal_lahir
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $oldMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pensiun')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($oldMilestone);
        $oldMilestoneDate = $oldMilestone->milestone_date->toDateString();
        $oldMilestoneId = $oldMilestone->id;

        // Update tanggal_lahir (change birth year)
        $response = $this->actingAs($user)->put(route('admin.pegawai.update', $employee->id), [
            'nama' => $employee->nama,
            'nip' => $employee->nip,
            'email' => $employee->email,
            'tanggal_lahir' => '1968-03-20', // ← Changed birth year by 1 year
            'tanggal_pensiun' => null,
        ]);

        $response->assertRedirect();

        // Verify: Old milestone invalidated
        $oldMilestone->refresh();
        $this->assertFalse($oldMilestone->is_active, 'Old milestone should be invalidated');

        // Verify: New milestone created with recalculated date
        $newMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pensiun')
            ->where('is_active', true)
            ->where('id', '!=', $oldMilestoneId)
            ->first();

        $this->assertNotNull($newMilestone, 'New milestone should be created with recalculated date');
        $this->assertNotEquals($oldMilestoneDate, $newMilestone->milestone_date->toDateString(), 'New milestone date should be different after birth date change');
        $this->assertFalse($newMilestone->metadata['is_manual'] ?? true, 'Should be calculated, not manual');
    }

    /**
     * Test: Mengubah field lain (nama, email) TIDAK memicu sync milestone.
     */
    public function test_updating_non_pension_fields_does_not_trigger_milestone_sync(): void
    {
        $this->seed(ReferenceSeeder::class);

        $user = User::factory()->create(['role' => 'super_admin']);
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'nama' => 'Old Name',
            'email' => 'old@example.com',
            'tanggal_lahir' => '1967-03-20',
            'tanggal_pensiun' => '2032-06-15',
        ]);

        // Initial sync
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $milestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pensiun')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($milestone);
        $milestoneId = $milestone->id;
        $milestoneCalculatedAt = $milestone->calculated_at;

        // Wait a bit to ensure timestamp would be different if recalculated
        sleep(1);

        // Update non-pension fields only
        $response = $this->actingAs($user)->put(route('admin.pegawai.update', $employee->id), [
            'nama' => 'New Name', // ← Changed
            'nip' => $employee->nip,
            'email' => 'new@example.com', // ← Changed
            'tanggal_lahir' => '1967-03-20', // ← Same
            'tanggal_pensiun' => '2032-06-15', // ← Same
        ]);

        $response->assertRedirect();

        // Verify: Milestone NOT recalculated
        $milestoneAfter = EmployeeMilestone::find($milestoneId);
        $this->assertNotNull($milestoneAfter);
        $this->assertTrue($milestoneAfter->is_active);
        $this->assertEquals($milestoneCalculatedAt->toDateTimeString(), $milestoneAfter->calculated_at->toDateTimeString(), 'Milestone should NOT be recalculated for non-pension field changes');
    }

    /**
     * Test: Scheduler menggunakan milestone terbaru setelah update.
     */
    public function test_scheduler_uses_updated_pension_milestone(): void
    {
        $this->seed(ReferenceSeeder::class);

        $user = User::factory()->create(['role' => 'super_admin']);
        $employee = Employee::factory()->create([
            'status_aktif' => 'Aktif',
            'tanggal_lahir' => '1967-03-20',
            'tanggal_pensiun' => now()->addYear()->toDateString(), // ← 1 year from now
        ]);

        // Initial sync
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        // Update to further future
        $newPensionDate = now()->addYears(5)->toDateString();
        $this->actingAs($user)->put(route('admin.pegawai.update', $employee->id), [
            'nama' => $employee->nama,
            'nip' => $employee->nip,
            'email' => $employee->email,
            'tanggal_lahir' => $employee->tanggal_lahir->toDateString(),
            'tanggal_pensiun' => $newPensionDate,
        ]);

        // Verify: Only one active pension milestone
        $activeMilestones = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pensiun')
            ->where('is_active', true)
            ->get();

        $this->assertCount(1, $activeMilestones, 'Should have exactly one active pension milestone');
        $this->assertEquals($newPensionDate, $activeMilestones->first()->milestone_date->toDateString());
    }
}
