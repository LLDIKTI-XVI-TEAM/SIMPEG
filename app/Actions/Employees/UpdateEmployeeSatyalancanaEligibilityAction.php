<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Services\AuditService;
use Illuminate\Http\Request;

class UpdateEmployeeSatyalancanaEligibilityAction
{
    /**
     * Mengubah kelayakan manual Satyalancana Fase 1 dan mencatat audit perubahan.
     */
    public function execute(Employee $employee, bool $isEligible, ?string $note, Request $request): Employee
    {
        $normalizedNote = $note !== null ? trim($note) : null;
        $normalizedNote = $normalizedNote !== '' ? $normalizedNote : null;

        $before = [
            'is_satyalancana_eligible' => $employee->is_satyalancana_eligible,
            'satyalancana_note' => $employee->satyalancana_note,
        ];

        $employee->update([
            'is_satyalancana_eligible' => $isEligible,
            'satyalancana_note' => $normalizedNote,
        ]);

        $employee->refresh();

        $after = [
            'is_satyalancana_eligible' => $employee->is_satyalancana_eligible,
            'satyalancana_note' => $employee->satyalancana_note,
        ];

        AuditService::log('UPDATE', 'Employee', $employee->id, $before, $after, $request);

        return $employee;
    }
}
