<?php

namespace App\Http\Requests\Cuti;

use App\Models\Employee;
use App\Support\Rbac\CutiPermissionMatrixPolicy;
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
            && CutiPermissionMatrixPolicy::isAssignableToRole('cuti.configure', (string) $actor->getEffectiveRole())
            && $actor->hasPermission('cuti.configure');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'approver_employee_id' => [
                'required',
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
}
