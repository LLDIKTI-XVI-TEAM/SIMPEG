<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KepalaBagianEmployeeFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'kepala_bagian';
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'cuti'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
        ];
    }
}
