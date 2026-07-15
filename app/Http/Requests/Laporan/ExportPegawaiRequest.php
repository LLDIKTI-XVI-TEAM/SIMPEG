<?php

namespace App\Http\Requests\Laporan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportPegawaiRequest extends FormRequest
{
    /** @var list<string> */
    protected const REPORTING_ROLES = [
        'super_admin',
        'admin_kepegawaian',
        'pimpinan',
    ];

    public function authorize(): bool
    {
        return in_array($this->user()?->role, self::REPORTING_ROLES, true);
    }

    /** @return array<string, list<string|Rule>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:255'],
            'golongan' => ['nullable', 'string', 'max:50'],
            'jenis' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:100'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'pensiun_dari' => ['nullable', 'date_format:Y-m-d'],
            'pensiun_sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:pensiun_dari'],
            'sort' => ['nullable', 'string', Rule::in(['nama', 'nip', 'golongan'])],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'unit' => 'unit kerja',
            'jenis' => 'jenis pegawai',
            'pensiun_dari' => 'tanggal pensiun mulai',
            'pensiun_sampai' => 'tanggal pensiun sampai',
        ];
    }
}
