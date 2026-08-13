<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi pilihan pegawai untuk nonaktifkan massal sebelum mutasi dan audit batch dijalankan.
 */
class BulkDeactivateEmployeesRequest extends FormRequest
{
    /**
     * Menjaga bulk mutation hanya untuk pengelola pegawai dengan permission eksplisit.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true)
            && $user->hasPermission('employees.deactivate');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ids.required' => 'Tidak ada data pegawai yang dipilih.',
            'ids.array' => 'Data pegawai yang dipilih tidak valid.',
            'ids.min' => 'Tidak ada data pegawai yang dipilih.',
            'ids.*.required' => 'Data pegawai yang dipilih tidak valid.',
            'ids.*.uuid' => 'Data pegawai yang dipilih tidak valid.',
        ];
    }
}
