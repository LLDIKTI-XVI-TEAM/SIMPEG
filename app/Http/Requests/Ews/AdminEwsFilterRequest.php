<?php

namespace App\Http\Requests\Ews;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminEwsFilterRequest extends FormRequest
{
    /** Otorisasi fitur tetap ditegakkan middleware role dan permission pada route. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', Rule::in(ListActiveEwsAlertsAction::allowedEventFilters())],
            'status' => ['nullable', 'string', Rule::in(ListActiveEwsAlertsAction::allowedStatusFilters())],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
