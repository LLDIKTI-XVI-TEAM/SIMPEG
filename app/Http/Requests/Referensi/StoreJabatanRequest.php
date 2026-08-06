<?php

namespace App\Http\Requests\Referensi;

use Illuminate\Foundation\Http\FormRequest;

class StoreJabatanRequest extends FormRequest
{
    /** Pengelolaan referensi jabatan dibatasi Super Admin, sejalan dengan gate data master. */
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    /**
     * default_bup adalah sumber pertama perhitungan batas usia pensiun, dengan
     * fallback ke maks_usia_pensiun pada jenis jabatan. Rentangnya hanya pagar
     * teknis: angka di bawahnya menghasilkan tanggal pensiun di masa lalu dan
     * memicu peringatan EWS massal, sedangkan angka resminya tetap kebijakan
     * yang boleh disesuaikan Admin tanpa perubahan kode.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nama' => 'required|string|max:255|unique:ref_jabatan,nama',
            'jenis_jabatan_id' => 'nullable|uuid|exists:ref_jenis_jabatan,id',
            'eselon_id' => 'nullable|uuid|exists:ref_eselon,id',
            'default_bup' => 'nullable|integer|min:50|max:70',
            'keterangan' => 'nullable|string|max:255',
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
