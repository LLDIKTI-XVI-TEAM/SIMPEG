<?php

namespace App\Http\Requests\Import;

use App\Support\EmployeeImport\ImportColumnMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveImportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Bypass otorisasi di environment lokal saat flag disable auth aktif.
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && (
                $user->hasPermission('employees.import')
                || $user->getEffectiveRole() === 'super_admin'
                || in_array($user->role, ['super_admin', 'admin_kepegawaian'], true)
                || in_array($user->getEffectiveRole(), ['super_admin', 'admin_kepegawaian'], true)
            );
    }

    public function rules(): array
    {
        return [
            'mapping' => ['required', 'array', 'min:1'],
            // Nilai mapping hanya boleh target kanonis yang diakui mapper atau penanda "tidak dipakai".
            'mapping.*' => [
                'string',
                Rule::in(array_merge(ImportColumnMapping::targets(), [ImportColumnMapping::IGNORE])),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'mapping.required' => 'Pemetaan kolom wajib dikirim.',
            'mapping.*.in' => 'Target pemetaan tidak dikenal. Pilih field SIMPEG yang tersedia atau opsi tidak dipakai.',
        ];
    }
}
