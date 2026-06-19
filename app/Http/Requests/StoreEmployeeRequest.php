<?php

namespace App\Http\Requests;

use App\Support\EmployeeValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Test routes (no auth middleware) — allow in local/testing
        if ($this->user() === null) {
            return app()->environment('local', 'testing');
        }

        return in_array($this->user()->role, ['super_admin', 'admin_kepegawaian'], true);
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
