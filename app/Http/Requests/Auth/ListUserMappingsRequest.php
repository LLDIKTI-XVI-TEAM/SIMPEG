<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ListUserMappingsRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:super_admin,admin_kepegawaian,pimpinan,kepala_bagian,pegawai'],
            'status' => ['nullable', 'in:terhubung,belum_ada_user,identifier_kosong,role_kosong'],
            'per_page' => ['nullable', 'integer', 'in:5,10,25,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'search' => 'Kata Pencarian',
            'role' => 'Role Internal SIMPEG',
            'status' => 'Status Mapping',
            'per_page' => 'Jumlah Data per Halaman',
            'page' => 'Halaman',
        ];
    }
}
