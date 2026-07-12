<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class LeaveReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'pimpinan';
    }

    public function rules(): array
    {
        return [
            'tahun' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'bulan' => ['nullable', 'integer', 'between:1,12'],
            'employee_id' => ['nullable', 'uuid'],
            'unit_kerja_id' => ['nullable', 'uuid'],
            'jenis_cuti_id' => ['nullable', 'uuid'],
        ];
    }
}
