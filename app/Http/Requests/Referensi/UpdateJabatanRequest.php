<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefJabatan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJabatanRequest extends FormRequest
{
    /** Pengelolaan referensi jabatan dibatasi Super Admin, sejalan dengan gate data master. */
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    /**
     * Aturan sama dengan penambahan, kecuali keunikan nama yang mengabaikan baris
     * yang sedang disunting agar admin dapat mengubah kolom lain tanpa mengganti nama.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $jabatan = $this->route('jabatan');
        $jabatanId = $jabatan instanceof RefJabatan ? $jabatan->id : null;

        return [
            'nama' => ['required', 'string', 'max:255', Rule::unique('ref_jabatan', 'nama')->ignore($jabatanId)],
            'jenis_jabatan_id' => ['nullable', 'uuid', 'exists:ref_jenis_jabatan,id'],
            'eselon_id' => ['nullable', 'uuid', 'exists:ref_eselon,id'],
            'default_bup' => ['nullable', 'integer', 'min:50', 'max:70'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nama' => 'Nama jabatan',
            'jenis_jabatan_id' => 'Jenis jabatan',
            'eselon_id' => 'Eselon',
            'default_bup' => 'BUP default',
            'keterangan' => 'Keterangan',
        ];
    }
}
