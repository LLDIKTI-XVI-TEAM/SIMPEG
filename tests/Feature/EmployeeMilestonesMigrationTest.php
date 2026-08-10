<?php

namespace Tests\Feature;

use App\Models\Employee;
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
}
