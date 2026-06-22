<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Support\EmployeeValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return EmployeeValidationRules::update($employee);
    }

    public function attributes(): array
    {
        return EmployeeValidationRules::attributes();
    }
}
