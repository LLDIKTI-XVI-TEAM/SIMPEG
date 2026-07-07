<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi perubahan PYBMC global sebagai final approver default chain cuti.
 */
class GlobalPybmcConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('cuti.configure_chain');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'approver_employee_id' => ['required', 'exists:employees,id'],
            'pybmc_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
