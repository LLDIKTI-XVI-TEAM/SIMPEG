<?php

namespace App\Services\Employees;

use App\Models\Employee;

/** Hasil immutable action status agar pemanggil dapat membedakan apply langsung dan jadwal. */
final readonly class EmployeeStatusChangeResult
{
    public const STATE_APPLIED = 'applied';

    public const STATE_SCHEDULED = 'scheduled';

    public function __construct(
        public Employee $employee,
        public string $state,
        public string $effectiveDate,
    ) {}

    public function isScheduled(): bool
    {
        return $this->state === self::STATE_SCHEDULED;
    }
}
