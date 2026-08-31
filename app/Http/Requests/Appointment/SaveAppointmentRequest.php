<?php

namespace App\Http\Requests\Appointment;

use Illuminate\Foundation\Http\FormRequest;

class SaveAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return $user->getEffectiveRole() === 'super_admin'
            || $user->hasPermission('employee_histories.create')
            || $user->hasPermission('employee_histories.update')
            || $user->hasPermission('employees.update');
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'jenis_pengangkatan' => ['required', 'string', 'max:100'],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
            'tmt_pengangkatan' => ['required', 'date'],
            'file_sk' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jenis_pengangkatan.required' => 'Jenis pengangkatan wajib dipilih atau diisi.',
            'no_sk.required' => 'Nomor SK pengangkatan wajib diisi.',
            'tanggal_sk.required' => 'Tanggal SK pengangkatan wajib diisi.',
            'tanggal_sk.date' => 'Format tanggal SK tidak valid.',
            'tmt_pengangkatan.required' => 'TMT pengangkatan wajib diisi.',
            'tmt_pengangkatan.date' => 'Format TMT pengangkatan tidak valid.',
            'file_sk.mimes' => 'Format berkas SK harus berupa PDF, JPG, JPEG, atau PNG.',
            'file_sk.max' => 'Ukuran berkas SK tidak boleh lebih dari 10 MB.',
        ];
    }
}
