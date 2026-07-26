<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefJenisJabatan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJenisJabatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Data referensi memengaruhi seluruh dropdown dan riwayat pegawai;
        // hanya super_admin yang boleh mengelolanya (lapisan kedua di atas
        // role middleware pada route).
        return $this->user()?->role === 'super_admin';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $jenisJabatan = $this->route('jenisJabatan');
        $jenisJabatanId = $jenisJabatan instanceof RefJenisJabatan ? $jenisJabatan->id : null;

        return [
            // Nama tetap dijaga unik di sini karena tabel tidak punya unique
            // constraint di database (lihat catatan di StoreJenisJabatanRequest).
            'nama' => ['required', 'string', 'max:100', Rule::unique('ref_jenis_jabatan', 'nama')->ignore($jenisJabatanId)],
            'maks_usia_pensiun' => ['required', 'integer', 'min:1', 'max:100'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'nama' => 'Nama jenis jabatan',
            'maks_usia_pensiun' => 'Maksimal usia pensiun',
            'catatan' => 'Catatan',
        ];
    }
}
