<?php

namespace App\Http\Requests\Employee;

class UpdateEmployeeFamilyRequest extends StoreEmployeeFamilyRequest
{
    public function authorize(): bool
    {
        // Bypass otorisasi di environment lokal saat flag disable auth aktif.
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        return $user !== null && $user->hasPermission('employee_families.update');
    }
}
