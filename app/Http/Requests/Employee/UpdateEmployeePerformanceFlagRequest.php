<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeePerformanceFlagRequest extends FormRequest
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
            'is_kinerja_baik' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'is_kinerja_baik' => 'status kinerja baik',
        ];
    }
}
