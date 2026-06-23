<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (app()->environment('local')
            && filter_var(env('SIMPEG_DISABLE_EMPLOYEE_API_AUTH', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'golongan' => ['nullable', 'string', 'max:20'],
            'jenis_pegawai_id' => ['nullable', 'uuid', 'exists:ref_jenis_pegawai,id'],
            'status_aktif' => ['nullable', 'in:Aktif,Non-Aktif,Pensiun,Mutasi'],
            'sort' => [
                'nullable',
                'in:nama_lengkap,nip,golongan_terakhir,jabatan_terakhir,jenis_pegawai_id,status_aktif,created_at',
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
            'jenis_pegawai_id' => 'Jenis Pegawai',
            'status_aktif' => 'Status Aktif',
            'sort' => 'Kolom Urutan',
            'direction' => 'Arah Urutan',
            'per_page' => 'Jumlah Data per Halaman',
        ];
    }
}
