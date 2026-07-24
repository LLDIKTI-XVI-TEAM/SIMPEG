<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'keycloak_id' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'in:super_admin,admin_kepegawaian,pimpinan,kepala_bagian,pegawai'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.required' => 'Pegawai wajib dipilih.',
            'employee_id.uuid' => 'Identifier pegawai tidak valid.',
            'employee_id.exists' => 'Pegawai yang dipilih tidak ditemukan.',
            'keycloak_id.required' => 'Identifier Keycloak wajib diisi. Disconnect belum tersedia pada halaman ini.',
            'keycloak_id.max' => 'Identifier Keycloak tidak boleh lebih dari 255 karakter.',
            'role.required' => 'Role wajib dipilih.',
            'role.in' => 'Role yang dipilih tidak valid.',
        ];
    }
}
