<?php

namespace App\Http\Requests;

use App\Support\EmployeeValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
