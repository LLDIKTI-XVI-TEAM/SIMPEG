<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi pilihan pegawai nonaktif untuk pemulihan massal sebelum mutasi dan audit batch dijalankan.
 */
class BulkRestoreEmployeesRequest extends FormRequest
{
    /**
     * Pemulihan massal mempertahankan batas Super Admin yang sudah ditetapkan pada route.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->role === 'super_admin'
            && $user->hasPermission('employees.restore');
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
            'ids.required' => 'Tidak ada pegawai yang dipilih.',
            'ids.array' => 'Data pegawai yang dipilih tidak valid.',
            'ids.min' => 'Tidak ada pegawai yang dipilih.',
            'ids.*.required' => 'Data pegawai yang dipilih tidak valid.',
            'ids.*.uuid' => 'Data pegawai yang dipilih tidak valid.',
        ];
    }
}
