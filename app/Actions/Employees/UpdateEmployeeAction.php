<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;

class UpdateEmployeeAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    /**
     * Memperbarui pegawai dan menghapus foto lama hanya setelah foto pengganti tersimpan.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Employee $employee, array $data, Request $request): Employee
    {
        $oldValues = $employee->toArray();
        $oldPhotoPath = $employee->foto;

        if ($request->hasFile('foto')) {
            $data['foto'] = $this->files->storePhoto($request->file('foto'));
        }

        $employee->update($data);
        $employee->refresh();

        if ($request->hasFile('foto')) {
            $this->files->deletePublicFile($oldPhotoPath);
        }

        AuditService::log('UPDATE', 'Employee', $employee->id, $oldValues, $employee->toArray(), $request);

        return $employee;
    }
}
