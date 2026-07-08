<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeSatyalancanaEligibilityRequest extends FormRequest
{
    /**
     * Otorisasi utama tetap berada di route middleware agar konsisten dengan gate backend SIMPEG.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'is_satyalancana_eligible' => ['required', 'boolean'],
            'satyalancana_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'is_satyalancana_eligible' => 'status kelayakan Satyalancana',
            'satyalancana_note' => 'catatan Satyalancana',
        ];
    }
}
