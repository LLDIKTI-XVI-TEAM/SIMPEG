<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeEmployeeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'pegawai_id' => ['required', 'uuid', 'exists:employees,id'],
            'status_pegawai_id' => ['required', 'uuid', Rule::exists('ref_status_pegawai', 'id')->where('is_active', true)],
            'tanggal' => ['required', 'date'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'berkas' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    public function attributes(): array
    {
        return [
            'pegawai_id' => 'Pegawai',
            'status_pegawai_id' => 'Status Baru',
            'tanggal' => 'Tanggal Efektif',
            'keterangan' => 'Keterangan',
            'berkas' => 'Berkas Pendukung',
        ];
    }
}
