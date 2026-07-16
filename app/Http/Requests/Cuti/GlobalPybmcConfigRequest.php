<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'approver_employee_id' => [
                'required',
                Rule::exists('employees', 'id')
                    ->where('status_aktif', 'Aktif')
                    ->whereNull('deleted_at'),
            ],
            'pybmc_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'approver_employee_id.exists' => 'PYBMC global harus merupakan pegawai aktif.',
        ];
    }
}
