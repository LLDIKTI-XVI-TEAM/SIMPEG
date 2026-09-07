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

        return $user !== null && $user->hasPermission('employees.import');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
