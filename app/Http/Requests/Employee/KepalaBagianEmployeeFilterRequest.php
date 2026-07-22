<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KepalaBagianEmployeeFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'kepala_bagian';
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'cuti', 'dinas_luar'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'golongan' => ['nullable', 'string', Rule::in(['I', 'II', 'III', 'IV'])],
            'unit_kerja_id' => ['nullable', 'exists:ref_unit_kerja,id'],
            'jenis_pegawai_id' => ['nullable', 'exists:ref_jenis_pegawai,id'],
        ];
    }
}
