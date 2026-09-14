<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ListEmployeesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('show_nonaktif')) {
            return;
        }

        $showNonaktif = filter_var(
            $this->input('show_nonaktif'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );

        if ($showNonaktif !== null) {
            $this->merge(['show_nonaktif' => $showNonaktif ? 1 : 0]);
        }
    }

    public function authorize(): bool
    {
        if (app()->environment('local')
            && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && $user->hasPermission('employees.read');
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'golongan' => ['nullable', 'string', 'max:20'],
            'unit_kerja_id' => ['nullable', 'uuid', 'exists:ref_unit_kerja,id'],
            'jenis_pegawai_id' => ['nullable', 'uuid', 'exists:ref_jenis_pegawai,id'],
            'status_pegawai_id' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if ($value !== 'all' && ! Str::isUuid($value)) {
                    $fail('Format status pegawai tidak valid.');
                }
            }],
            'status_aktif' => ['nullable', 'in:Aktif,Non-Aktif,Pensiun,Mutasi'],
            'show_nonaktif' => ['nullable', 'boolean'],
            'sort' => [
                'nullable',
                'in:nama_lengkap,nip,golongan_terakhir,jabatan_terakhir,jenis_pegawai_id,status_pegawai_id,status_aktif,created_at',
            ],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ];
    }

    public function attributes(): array
    {
        return [
            'search' => 'Kata Pencarian',
            'golongan' => 'Golongan',
            'unit_kerja_id' => 'Unit Kerja',
            'jenis_pegawai_id' => 'Jenis Pegawai',
            'status_pegawai_id' => 'Status Pegawai',
            'status_aktif' => 'Status Aktif',
            'show_nonaktif' => 'Tampilkan Pegawai Non-Aktif',
            'sort' => 'Kolom Urutan',
            'direction' => 'Arah Urutan',
            'per_page' => 'Jumlah Data per Halaman',
        ];
    }
}
