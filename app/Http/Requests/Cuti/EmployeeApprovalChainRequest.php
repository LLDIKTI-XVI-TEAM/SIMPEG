<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi konfigurasi chain approval per pegawai.
 * PYBMC final boleh diambil dari konfigurasi global sehingga form hanya perlu mengirim step non-final.
 */
class EmployeeApprovalChainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('cuti.configure_chain');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'steps' => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.step_type' => ['required', 'string', 'in:kepala_bagian,verifier'],
            'steps.*.role_label' => ['required', 'string', 'max:100'],
            'steps.*.approver_employee_id' => ['required', 'exists:employees,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
