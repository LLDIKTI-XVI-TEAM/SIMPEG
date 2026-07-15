<?php

namespace App\Http\Requests\Ews;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KepalaBagianEwsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'kepala_bagian';
    }

    public function rules(): array
    {
        return [
            'event' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'ditangani', 'tidak_perlu', 'kedaluwarsa'])],
        ];
    }
}
