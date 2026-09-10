<?php

namespace App\Http\Requests\Cuti;

use App\Models\Employee;
use App\Services\Employees\EmployeeDashboardScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Memvalidasi perubahan PYBMC global sebagai final approver default chain cuti.
 */
class GlobalPybmcConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null
            && $actor->hasPermission('cuti.configure')
            && app(EmployeeDashboardScopeService::class)->hasGlobalIdentityScope($actor);
    }

    /** Kegagalan validasi tetap diarahkan ke panel PYBMC global internal. */
    protected function getRedirectUrl(): string
    {
        return route('cuti.config', ['tab' => 'pybmc']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'approver_employee_id' => [
                'required',
                'bail',
                'uuid',
                Rule::exists('employees', 'id')
                    ->where(fn ($query) => $query->whereIn('id', Employee::query()->whereActiveStatus()->select('id'))),
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

    /** Label validasi mengikuti istilah yang terlihat pada form, bukan nama field internal. */
    public function attributes(): array
    {
        return [
            'approver_employee_id' => 'PYBMC Global',
            'pybmc_reason' => 'Alasan PYBMC Global',
        ];
    }
}
