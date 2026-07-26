<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefJenjangPendidikan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJenjangPendidikanRequest extends FormRequest
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
        $jenjang = $this->route('jenjang');
        $jenjangId = $jenjang instanceof RefJenjangPendidikan ? $jenjang->id : null;

        return [
            // Nama unik karena riwayat pendidikan lama mencocokkan jenjang
            // berdasarkan nama; duplikat membuat pencocokan menjadi ambigu.
            'nama' => ['required', 'string', 'max:50', Rule::unique('ref_jenjang_pendidikan', 'nama')->ignore($jenjangId)],
            'urutan' => ['nullable', 'integer', 'min:0'],
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
