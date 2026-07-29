<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefGolongan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGolonganRequest extends FormRequest
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
        $golongan = $this->route('golongan');
        $golonganId = $golongan instanceof RefGolongan ? $golongan->id : null;

        return [
            'kode' => ['required', 'string', 'max:10', Rule::unique('ref_golongan', 'kode')->ignore($golonganId)],
            'nama' => ['required', 'string', 'max:100'],
            'urutan' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'kode' => 'Kode golongan',
            'nama' => 'Nama golongan',
            'urutan' => 'Urutan golongan',
        ];
    }
}
