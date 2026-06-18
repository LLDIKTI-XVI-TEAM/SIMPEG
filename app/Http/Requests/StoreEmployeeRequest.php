<?php

namespace App\Http\Requests;

use App\Support\EmployeeValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        return EmployeeValidationRules::create();
    }

    public function attributes(): array
    {
        return EmployeeValidationRules::attributes();
    }
}
