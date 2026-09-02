<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteImportBatchRequest extends FormRequest
{
    /** Hanya pengelola data pegawai yang boleh mengantrekan eksekusi batch impor. */
    public function authorize(): bool
    {
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

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
