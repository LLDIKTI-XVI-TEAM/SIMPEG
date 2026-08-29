<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\RefStatusPegawai;

/** Hasil immutable mutasi lifecycle untuk menerbitkan intent setelah transaksi selesai. */
final readonly class EmployeeStatusMutationResult
{
    public function __construct(
        public Employee $employee,
        public RefStatusPegawai $targetStatus,
        public bool $changed,
        public bool $wasActive,
        public bool $isActive,
        public string $reason,
        public ?string $statusNote,
    ) {}
}
