<?php

namespace App\Http\Requests\Ews;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Menjaga pagination EWS pribadi mengikuti opsi yang tersedia di antarmuka.
 */
class MyEwsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string|Rule>> */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
