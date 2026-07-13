<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportCustomEmployeeReportRequest extends FormRequest
{
    private const ALLOWED_COLUMNS = [
        'nip',
        'nama',
        'status',
        'jabatan',
        'unit',
        'golongan',
        'jenis_pegawai',
        'pendidikan',
        'tanggal_pensiun',
    ];

    public function authorize(): bool
    {
        return $this->user()?->role === 'pimpinan';
    }

    public function rules(): array
    {
        return [
            'columns' => [Rule::requiredIf($this->routeIs('pimpinan.laporan.pegawai.custom')), 'array', 'min:1'],
            'columns.*' => ['required', 'string', 'distinct', Rule::in(self::ALLOWED_COLUMNS)],
            'status_pegawai_id' => ['nullable', 'uuid'],
            'unit_kerja_id' => ['nullable', 'uuid'],
            'jenis_pegawai_id' => ['nullable', 'uuid'],
            'golongan' => ['nullable', 'string', 'max:10'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'pensiun_dari' => ['nullable', 'date'],
            'pensiun_sampai' => ['nullable', 'date', 'after_or_equal:pensiun_dari'],
        ];
    }

    public function attributes(): array
    {
        return [
            'columns' => 'kolom laporan',
            'pensiun_dari' => 'periode pensiun dari',
            'pensiun_sampai' => 'periode pensiun sampai',
        ];
    }
}
