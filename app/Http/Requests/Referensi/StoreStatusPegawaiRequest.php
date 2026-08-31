<?php

namespace App\Http\Requests\Referensi;

use Illuminate\Foundation\Http\FormRequest;

class StoreStatusPegawaiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('reference_tables.manage');
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        // is_default sengaja tidak divalidasi/diteruskan: status default
        // dipakai sebagai fallback pegawai baru dan hasil import, sehingga
        // perpindahannya harus lewat keputusan terpisah, bukan form CRUD.
        return [
            'kode' => 'required|string|max:50|unique:ref_status_pegawai,kode',
            'nama' => 'required|string|max:50|unique:ref_status_pegawai,nama',
            'kelompok' => 'required|string|max:50',
            'keterangan' => 'nullable|string|max:255',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'kode' => 'Kode status pegawai',
            'nama' => 'Nama status pegawai',
            'kelompok' => 'Kelompok status',
            'keterangan' => 'Keterangan',
        ];
    }
}
