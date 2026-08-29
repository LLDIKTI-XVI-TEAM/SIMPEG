<?php

namespace App\Http\Requests\Ews;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KepalaBagianEwsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->getEffectiveRole() === 'kepala_bagian';
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', Rule::in(ListActiveEwsAlertsAction::allowedEventFilters())],
            'status' => ['nullable', Rule::in(['aktif', 'ditangani', 'tidak_perlu', 'kedaluwarsa'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
