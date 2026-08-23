<?php

namespace App\Http\Requests\Ews;

use Illuminate\Foundation\Http\FormRequest;

class PimpinanEwsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->getEffectiveRole() === 'pimpinan';
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
