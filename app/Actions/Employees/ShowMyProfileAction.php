<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Support\Employees\EmployeeDetailPayload;

class ShowMyProfileAction
{
    public function __construct(private readonly EmployeeDetailPayload $payload) {}

    /**
     * Mengambil profil pegawai milik user login, fail-closed jika belum terhubung.
     *
     * @return array<string, mixed>
     */
    public function execute(?Employee $employee): array
    {
        abort_if($employee === null, 404, 'Data pegawai untuk akun ini belum terhubung.');

        return $this->payload->response($this->payload->loadRelations($employee));
    }
}
