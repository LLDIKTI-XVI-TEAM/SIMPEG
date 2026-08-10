<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\PositionHistory;
use App\Models\RefJabatan;
use App\Services\Employees\TmtCalculatorService;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeePensionProvenanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Regression test untuk US-5.5 AC-3 provenance issue.
     *
     * Skenario yang diminta reviewer:
     * 1. tanggal_pensiun awal null
     * 2. sync dari jabatan A menghitung tanggal X
     * 3. tambah jabatan resmi B dengan BUP berbeda
     * 4. assert employees.tanggal_pensiun dan milestone pensiun berubah ke tanggal Y
     *
     * Issue: TmtCalculatorService menentukan manual/import dengan:
     *   $hadManualPensionDate = $employee->tanggal_pensiun !== null
     *
     * Tapi service yang sama juga menulis hasil kalkulasi ke employees.tanggal_pensiun.
     * Setelah kalkulasi pertama, field menjadi non-null dan sync berikutnya dapat
     * salah menganggap hasil kalkulasi sistem sebagai manual/import authoritative.
     */
    public function test_pension_date_recalculates_when_position_bup_changes(): void
    {
        $this->seed(ReferenceSeeder::class);

        // 1. Create employee with birth date, tanggal_pensiun = null
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1970-01-15',
            'tanggal_pensiun' => null, // ← Initially null
            'status_aktif' => 'Aktif',
        ]);

        // Create jabatan A with BUP 58
        $jabatanA = RefJabatan::create([
            'id' => fake()->uuid(),
            'nama' => 'Jabatan A Test',
            'default_bup' => 58,
            'is_active' => true,
        ]);

        // 2. Assign jabatan A via PositionHistory - should calculate pension date based on BUP 58
        PositionHistory::create([
            'employee_id' => $employee->id,
            'jabatan_id' => $jabatanA->id,
            'tmt_jabatan' => '2020-01-01',
            'no_sk' => 'SK-001',
            'tanggal_sk' => '2019-12-15',
        ]);

        // Sync milestones - should calculate pension date
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $employee->refresh();
        $firstPensionDate = $employee->tanggal_pensiun;
        $expectedFirstPensionDate = $employee->tanggal_lahir->copy()->addYears(58);

        $this->assertNotNull($firstPensionDate, 'Pension date should be calculated after first sync');
        $this->assertEquals(
            $expectedFirstPensionDate->toDateString(),
            $firstPensionDate->toDateString(),
            'First pension date should be based on BUP 58'
        );

        // Verify milestone created
        $firstMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pensiun')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($firstMilestone, 'Pension milestone should be created');
        $this->assertEquals(
            $expectedFirstPensionDate->toDateString(),
            $firstMilestone->milestone_date->toDateString(),
            'Milestone should match calculated pension date'
        );

        // 3. Add jabatan B with different BUP 60
        $jabatanB = RefJabatan::create([
            'id' => fake()->uuid(),
            'nama' => 'Jabatan B Test',
            'default_bup' => 60, // ← Different BUP
            'is_active' => true,
        ]);

        // Delete old position history
        PositionHistory::where('employee_id', $employee->id)->delete();

        // Assign jabatan B via PositionHistory
        PositionHistory::create([
            'employee_id' => $employee->id,
            'jabatan_id' => $jabatanB->id,
            'tmt_jabatan' => '2024-01-01',
            'no_sk' => 'SK-002',
            'tanggal_sk' => '2023-12-15',
        ]);

        // 4. Sync again - pension date MUST recalculate based on new BUP
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $employee->refresh();
        $secondPensionDate = $employee->tanggal_pensiun;
        $expectedSecondPensionDate = $employee->tanggal_lahir->copy()->addYears(60);

        // CRITICAL ASSERTION: Pension date must change to reflect new BUP
        $this->assertNotNull($secondPensionDate, 'Pension date should still exist after second sync');
        $this->assertEquals(
            $expectedSecondPensionDate->toDateString(),
            $secondPensionDate->toDateString(),
            'Pension date MUST recalculate based on new BUP 60, not stay at old BUP 58'
        );

        // Verify milestone updated
        $secondMilestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pensiun')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($secondMilestone, 'Pension milestone should still be active');
        $this->assertEquals(
            $expectedSecondPensionDate->toDateString(),
            $secondMilestone->milestone_date->toDateString(),
            'Milestone MUST update to reflect new BUP'
        );

        // Verify metadata shows this is calculated, not manual
        $this->assertFalse(
            $secondMilestone->metadata['is_manual'] ?? true,
            'Milestone should be marked as calculated, not manual'
        );
    }

    /**
     * Test: Manual/import pension date should NOT be overwritten by BUP calculation.
     */
    public function test_manual_pension_date_is_preserved_when_position_changes(): void
    {
        $this->seed(ReferenceSeeder::class);

        $manualPensionDate = '2035-06-30'; // ← Explicit manual date

        // Create employee with manual pension date
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1970-01-15',
            'tanggal_pensiun' => $manualPensionDate, // ← Manual/import value
            'status_aktif' => 'Aktif',
        ]);

        // Create jabatan with BUP 58
        $jabatan = RefJabatan::create([
            'id' => fake()->uuid(),
            'nama' => 'Jabatan Test Manual',
            'default_bup' => 58,
            'is_active' => true,
        ]);

        PositionHistory::create([
            'employee_id' => $employee->id,
            'jabatan_id' => $jabatan->id,
            'tmt_jabatan' => '2020-01-01',
            'no_sk' => 'SK-001',
            'tanggal_sk' => '2019-12-15',
        ]);

        // Sync - should respect manual date
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $employee->refresh();

        // Manual date must be preserved
        $this->assertEquals(
            $manualPensionDate,
            $employee->tanggal_pensiun->toDateString(),
            'Manual pension date must NOT be overwritten by BUP calculation'
        );

        // Verify milestone uses manual date
        $milestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pensiun')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($milestone);
        $this->assertEquals(
            $manualPensionDate,
            $milestone->milestone_date->toDateString(),
            'Milestone should use manual pension date'
        );
        $this->assertTrue(
            $milestone->metadata['is_manual'] ?? false,
            'Milestone should be marked as manual'
        );
    }

    /**
     * Test: Calculated pension date from first sync should be treated as calculated,
     * not manual, even after field becomes non-null.
     */
    public function test_calculated_pension_date_is_not_treated_as_manual_on_subsequent_syncs(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Employee starts with null pension date
        $employee = Employee::factory()->create([
            'tanggal_lahir' => '1970-01-15',
            'tanggal_pensiun' => null,
            'status_aktif' => 'Aktif',
        ]);

        $jabatan = RefJabatan::create([
            'id' => fake()->uuid(),
            'nama' => 'Jabatan Test Calc',
            'default_bup' => 58,
            'is_active' => true,
        ]);

        PositionHistory::create([
            'employee_id' => $employee->id,
            'jabatan_id' => $jabatan->id,
            'tmt_jabatan' => '2020-01-01',
            'no_sk' => 'SK-001',
            'tanggal_sk' => '2019-12-15',
        ]);

        // First sync - calculates pension date
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $employee->refresh();
        $firstPensionDate = $employee->tanggal_pensiun;

        $this->assertNotNull($firstPensionDate);

        // Second sync without changes - should not treat first calculation as manual
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $employee->refresh();

        // Milestone should still be marked as calculated, not manual
        $milestone = EmployeeMilestone::where('employee_id', $employee->id)
            ->where('type', 'pensiun')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($milestone);
        $this->assertFalse(
            $milestone->metadata['is_manual'] ?? true,
            'Calculated pension date should NOT be treated as manual on subsequent syncs'
        );
        $this->assertEquals('calculated_from_bup', $milestone->metadata['source'] ?? null);
    }
}
