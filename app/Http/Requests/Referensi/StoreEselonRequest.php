<?php

namespace App\Http\Requests\Referensi;

use Illuminate\Foundation\Http\FormRequest;

class StoreEselonRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Data referensi memengaruhi seluruh dropdown dan riwayat pegawai;
        // hanya super_admin yang boleh mengelolanya (lapisan kedua di atas
        // role middleware pada route).
        return $this->user()?->role === 'super_admin';
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'kode' => 'required|string|max:10|unique:ref_eselon,kode',
            'nama' => 'required|string|max:50',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'kode' => 'Kode eselon',
            'nama' => 'Nama eselon',
        ];
    }
}
