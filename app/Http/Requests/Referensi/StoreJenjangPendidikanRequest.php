<?php

namespace App\Http\Requests\Referensi;

use Illuminate\Foundation\Http\FormRequest;

class StoreJenjangPendidikanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('reference_tables.manage');
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            // Nama unik karena riwayat pendidikan lama mencocokkan jenjang
            // berdasarkan nama; duplikat membuat pencocokan menjadi ambigu.
            'nama' => 'required|string|max:50|unique:ref_jenjang_pendidikan,nama',
            'urutan' => 'nullable|integer|min:0',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'nama' => 'Nama jenjang',
            'urutan' => 'Urutan',
        ];
    }
}
