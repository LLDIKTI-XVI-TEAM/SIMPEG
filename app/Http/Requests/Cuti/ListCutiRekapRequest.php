<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ListCutiRekapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Menetapkan kontrak filter kanonis agar seluruh keluaran rekap memakai batas input yang sama.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'periode' => ['nullable', 'string', 'max:20'],
            'unit' => ['nullable', 'string', 'max:150'],
            'pegawai' => ['nullable', 'uuid'],
            'jenis' => ['nullable', 'uuid', 'exists:ref_jenis_cuti,id'],
        ];
    }

    /**
     * UUID berbentuk array atau rusak ditolak sebagai resource tak ditemukan agar PostgreSQL tidak menerima nilai berbahaya.
     */
    protected function prepareForValidation(): void
    {
        foreach (['pegawai', 'jenis'] as $field) {
            $value = $this->input($field);

            if ($value !== null && (is_array($value) || ! is_string($value) || ! Str::isUuid($value))) {
                abort(404);
            }
        }
    }
}
