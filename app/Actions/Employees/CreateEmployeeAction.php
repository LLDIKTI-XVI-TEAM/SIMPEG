<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;

class CreateEmployeeAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    /**
     * Membuat pegawai baru, termasuk penyimpanan foto dan audit create.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, Request $request): Employee
    {
        if ($request->hasFile('foto')) {
            $data['foto'] = $this->files->storePhoto($request->file('foto'));
        }

        $employee = Employee::create($data);

        AuditService::log('CREATE', 'Employee', $employee->id, null, $employee->getRawOriginal(), $request);

        return $employee;
    }
}
