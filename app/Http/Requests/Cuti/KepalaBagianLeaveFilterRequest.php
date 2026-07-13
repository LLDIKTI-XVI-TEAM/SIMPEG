<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

class KepalaBagianLeaveFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'kepala_bagian';
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'tahun' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'bulan' => ['nullable', 'integer', 'between:1,12'],
            'jenis_cuti_id' => ['nullable', 'uuid', 'exists:ref_jenis_cuti,id'],
        ];
    }
}
