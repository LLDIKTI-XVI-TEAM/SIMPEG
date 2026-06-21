<?php

namespace App\Http\Requests;

use App\Support\EmployeeValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi gagal-tertutup: tanpa user terautentikasi, tolak (tidak ada bypass dev/test).
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
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
