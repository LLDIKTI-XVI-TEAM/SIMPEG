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

    protected function prepareForValidation(): void
    {
        if (! $this->has('status')) {
            $this->merge([
                'status' => $this->filled('search') ? 'all' : 'menunggu_saya',
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'unit_kerja_id' => ['nullable', 'uuid'],
            'periode' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'jenis_cuti_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['all', 'menunggu_saya', 'menunggu', 'disetujui', 'perubahan', 'ditangguhkan', 'tidak_disetujui'])],
        ];
    }
}
