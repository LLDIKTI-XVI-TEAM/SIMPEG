<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class ValidateImportBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        return [
            'rows' => ['sometimes', 'required', 'array'],
            'rows.*.row' => ['required', 'integer', 'min:2'],
            'rows.*.data' => ['required', 'array'],
        ];
    }
}
