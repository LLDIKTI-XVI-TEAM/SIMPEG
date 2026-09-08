<?php

namespace App\Http\Requests\Appointment;

use App\Models\Appointment;
use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $employee = $this->route('employee');
        if (! $employee instanceof Employee || ! ($user = $this->user())) {
            return false;
        }

        $exists = Appointment::query()->where('employee_id', $employee->id)->exists();

        return $user->hasPermission($exists ? 'employee_histories.update' : 'employee_histories.create');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'jenis_pengangkatan' => ['required', 'string', Rule::in(['PNS', 'CPNS', 'PPPK'])],
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
            'jenis_pengangkatan.in' => 'Jenis pengangkatan hanya boleh bernilai PNS, CPNS, atau PPPK.',
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
