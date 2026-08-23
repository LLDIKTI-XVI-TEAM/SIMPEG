<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class RankHistoryReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->getEffectiveRole() === 'pimpinan';
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'uuid'],
            'golongan_id' => ['nullable', 'uuid'],
            'tahun' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
