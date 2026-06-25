<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Support\Employees\EmployeeDetailPayload;

class ShowEmployeeAction
{
    public function __construct(private readonly EmployeeDetailPayload $payload) {}

    /**
     * Mengambil payload detail pegawai dengan relasi lengkap namun field sensitif tetap dibatasi.
     *
     * @return array<string, mixed>
     */
    public function execute(Employee $employee): array
    {
        return $this->payload->response($this->payload->loadRelations($employee));
    }
}
