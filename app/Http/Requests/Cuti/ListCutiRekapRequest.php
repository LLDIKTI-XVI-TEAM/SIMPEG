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
            'periode' => ['bail', 'nullable', 'string', 'max:20', 'regex:/^(?:(?!0000)\d{4}(?:-(?:0[1-9]|1[0-2]))?|(?:Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember) (?!0000)\d{4})$/'],
            'unit' => ['bail', 'nullable', 'uuid', 'exists:ref_unit_kerja,id'],
            'pegawai' => ['bail', 'nullable', 'uuid', 'exists:employees,id'],
            'jenis' => ['bail', 'nullable', 'uuid', 'exists:ref_jenis_cuti,id'],
            'page' => ['bail', 'nullable', 'integer', 'min:1'],
            'page_saldo' => ['bail', 'nullable', 'integer', 'min:1'],
            'page_usage' => ['bail', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * UUID berbentuk array atau rusak ditolak sebagai resource tak ditemukan agar PostgreSQL tidak menerima nilai berbahaya.
     */
    protected function prepareForValidation(): void
    {
        foreach (['unit', 'pegawai', 'jenis'] as $field) {
            $value = $this->input($field);

            if ($value !== null && (is_array($value) || ! is_string($value) || ! Str::isUuid($value))) {
                abort(404);
            }
        }
    }
}
