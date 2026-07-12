<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PimpinanLeaveFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'pimpinan';
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'unit_kerja_id' => ['nullable', 'uuid'],
            'tahun' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'bulan' => ['nullable', 'integer', 'between:1,12'],
            'jenis_cuti_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['menunggu', 'disetujui', 'perubahan', 'ditangguhkan', 'tidak_disetujui'])],
        ];
    }
}
