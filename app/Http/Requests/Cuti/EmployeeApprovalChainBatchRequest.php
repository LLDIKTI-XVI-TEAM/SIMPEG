<?php

namespace App\Http\Requests\Cuti;

use App\Services\Cuti\EmployeeApprovalChainBatchService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeApprovalChainBatchRequest extends FormRequest
{
    /** Capability efektif diperiksa terpisah dari scope identitas pada service. */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('cuti.configure') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->replace([
            ...app(EmployeeApprovalChainBatchService::class)->normalize($this->all()),
            'preview_token' => $this->input('preview_token'),
        ]);
    }

    public function rules(): array
    {
        return [...EmployeeApprovalChainBatchService::draftRules(), 'preview_token' => [
            Rule::requiredIf($this->routeIs('cuti.config.batch.apply')), 'nullable', 'string', 'max:4096',
        ]];
    }

    public function attributes(): array
    {
        return [
            'employee_ids' => 'pilihan pegawai',
            'employee_ids.*' => 'pegawai terpilih',
            'mode' => 'mode penerapan',
            'verifiers' => 'rangkaian Verifikator',
            'verifiers.*.approver_employee_id' => 'pegawai Verifikator',
            'verifiers.*.role_label' => 'label peran Verifikator',
            'pybmc_mode' => 'pilihan PYBMC',
            'pybmc_employee_id' => 'pegawai PYBMC',
            'reason' => 'alasan penerapan',
            'preview_token' => 'pratinjau penerapan',
        ];
    }

    /** Unknown root key tidak boleh hilang diam-diam melalui validated(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['employee_ids', 'mode', 'verifiers', 'pybmc_mode', 'pybmc_employee_id', 'reason', 'preview_token']) !== []) {
                $validator->errors()->add('draft', 'Field draft tidak dikenal.');
            }
        });
    }
}
