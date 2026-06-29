<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveBalance;
use Illuminate\Database\Seeder;

class LeaveBalance2026Seeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::all();

        foreach ($employees as $employee) {
            LeaveBalance::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'tahun' => 2026,
                ],
                [
                    'jatah_awal' => 12,
                    'carry_over' => 0,
                    'terpakai' => 0,
                    'sisa' => 12,
                ]
            );
        }

        $this->command->info('Seeded leave balances for '.$employees->count().' employees (2026).');
    }
}
