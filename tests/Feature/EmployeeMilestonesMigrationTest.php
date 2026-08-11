<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMilestone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeMilestonesMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** Memastikan tabel milestone baru menyediakan kolom yang dibutuhkan scheduler EWS. */
    public function test_employee_milestones_schema_stores_calculated_milestones(): void
    {
        $this->assertTrue(Schema::hasTable('employee_milestones'));
        $this->assertTrue(Schema::hasColumns('employee_milestones', [
            'id',
            'employee_id',
            'type',
            'milestone_key',
            'milestone_date',
            'calculated_at',
            'metadata',
            'is_active',
        ]));

        $employee = Employee::factory()->create();
        $milestoneId = (string) Str::uuid();

        DB::table('employee_milestones')->insert([
            'id' => $milestoneId,
            'employee_id' => $employee->id,
            'type' => 'kenaikan_pangkat',
            'milestone_date' => '2027-01-01',
            'calculated_at' => '2026-08-11',
            'metadata' => json_encode(['required_years' => 4], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('employee_milestones', [
            'id' => $milestoneId,
            'employee_id' => $employee->id,
            'type' => 'kenaikan_pangkat',
            'is_active' => true,
        ]);
    }

    /** Database harus menolak dua milestone aktif untuk slot scalar pegawai yang sama. */
    public function test_duplicate_active_scalar_milestone_is_rejected(): void
    {
        $employee = Employee::factory()->create();

        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_PENSIUN,
            'milestone_date' => '2038-01-01',
            'calculated_at' => '2026-08-11',
            'metadata' => ['source' => 'calculated_from_bup'],
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_PENSIUN,
            'milestone_date' => '2040-01-01',
            'calculated_at' => '2026-08-11',
            'metadata' => ['source' => 'calculated_from_bup'],
            'is_active' => true,
        ]);
    }

    /** Riwayat milestone nonaktif dengan slot yang sama harus tetap dapat disimpan. */
    public function test_duplicate_inactive_milestones_are_allowed(): void
    {
        $employee = Employee::factory()->create();

        foreach (['2038-01-01', '2040-01-01'] as $milestoneDate) {
            EmployeeMilestone::create([
                'employee_id' => $employee->id,
                'type' => EmployeeMilestone::TYPE_PENSIUN,
                'milestone_date' => $milestoneDate,
                'calculated_at' => '2026-08-11',
                'metadata' => ['source' => 'calculated_from_bup'],
                'is_active' => false,
            ]);
        }

        $this->assertSame(2, EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_PENSIUN)
            ->where('is_active', false)
            ->count());
    }

    /** Tiga slot Satyalancana aktif harus dapat hidup berdampingan. */
    public function test_three_active_satyalancana_slots_are_allowed(): void
    {
        $employee = Employee::factory()->create();

        foreach ([10, 20, 30] as $years) {
            EmployeeMilestone::create([
                'employee_id' => $employee->id,
                'type' => EmployeeMilestone::TYPE_SATYALANCANA,
                'milestone_date' => "20{$years}-01-01",
                'calculated_at' => '2026-08-11',
                'metadata' => ['satyalancana_years' => $years],
                'is_active' => true,
            ]);
        }

        $this->assertSame(3, EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_SATYALANCANA)
            ->where('is_active', true)
            ->count());
    }

    /** Database harus menolak milestone aktif ganda untuk slot Satyalancana yang sama. */
    public function test_duplicate_active_satyalancana_slot_is_rejected(): void
    {
        $employee = Employee::factory()->create();

        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_SATYALANCANA,
            'milestone_date' => '2036-01-01',
            'calculated_at' => '2026-08-11',
            'metadata' => ['satyalancana_years' => 10],
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        EmployeeMilestone::create([
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_SATYALANCANA,
            'milestone_date' => '2037-01-01',
            'calculated_at' => '2026-08-11',
            'metadata' => ['satyalancana_years' => 10],
            'is_active' => true,
        ]);
    }
}
