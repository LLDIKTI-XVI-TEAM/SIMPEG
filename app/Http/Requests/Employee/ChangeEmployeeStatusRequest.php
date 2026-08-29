<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeEmployeeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('employees.update');
    }

    public function rules(): array
    {
        return [
            'pegawai_id' => ['required', 'uuid', 'exists:employees,id'],
            'status_pegawai_id' => ['required', 'uuid', Rule::exists('ref_status_pegawai', 'id')->where('is_active', true)],
            // K-STATUS-06: tanggal efektif masa depan DIPERBOLEHKAN dan disimpan sebagai
            // transisi terjadwal (snapshot tidak berubah sampai jatuh tempo).
            'tanggal' => ['required', 'date'],
            'keterangan' => ['required', 'string', 'min:3', 'max:2000'],
            'berkas' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tanggal.required' => 'Tanggal efektif status kepegawaian wajib diisi.',
            'tanggal.date' => 'Tanggal efektif status kepegawaian tidak valid.',
            'keterangan.required' => 'Keterangan wajib diisi.',
            'keterangan.min' => 'Keterangan minimal 3 karakter.',
            'keterangan.max' => 'Keterangan maksimal 2000 karakter.',
        ];
    }

    public function attributes(): array
    {
        return [
            'pegawai_id' => 'Pegawai',
            'status_pegawai_id' => 'Status Baru',
            'tanggal' => 'Tanggal Efektif Status Kepegawaian',
            'keterangan' => 'Keterangan',
            'berkas' => 'Berkas Pendukung',
        ];
    }
}
