<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi filter halaman konfigurasi chain cuti.
 * Filter dibatasi agar pencarian tetap aman dan tidak berubah menjadi query bebas ke data pegawai.
 */
class CutiConfigPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('cuti.configure');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'employee_id' => ['nullable', 'uuid', 'exists:employees,id'],
            'approver_search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
