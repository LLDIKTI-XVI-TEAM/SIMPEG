<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use App\Support\EmployeeValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (app()->environment('local')
            && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        $employeeParam = $this->route('employee');

        if ($employeeParam instanceof Employee) {
            $employee = $employeeParam;
        } else {
            $id = $this->route('id') ?? $employeeParam;
            $employee = Employee::findOrFail($id);
        }

        $rules = EmployeeValidationRules::update($employee);

        // Aturan tambahan khusus form UI web
        if (! $this->wantsJson() && ! $this->is('api/*')) {
            // Pangkat (Rank)
            $rules['pangkat_golongan_id'] = ['nullable', 'uuid', 'exists:ref_golongan,id'];
            $rules['pangkat_no_sk'] = ['nullable', 'string', 'max:255'];
            $rules['pangkat_tanggal_sk'] = ['nullable', 'date'];
            $rules['pangkat_tmt_pangkat'] = ['nullable', 'date'];
            $rules['file_sk_pangkat'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

            // Jabatan (Position)
            $rules['jabatan_nama_jabatan'] = ['nullable', 'string', 'max:255'];
            $rules['jabatan_jenis_jabatan_id'] = ['nullable', 'uuid', 'exists:ref_jenis_jabatan,id'];
            $rules['jabatan_eselon_id'] = ['nullable', 'uuid', 'exists:ref_eselon,id'];
            $rules['jabatan_unit_kerja_id'] = ['nullable', 'uuid', 'exists:ref_unit_kerja,id'];
            $rules['jabatan_no_sk'] = ['nullable', 'string', 'max:255'];
            $rules['jabatan_tanggal_sk'] = ['nullable', 'date'];
            $rules['jabatan_tmt_jabatan'] = ['nullable', 'date'];
            $rules['file_sk_jabatan'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

            // KGB (Salary)
            $rules['kgb_gaji_pokok'] = ['nullable', 'numeric', 'min:0'];
            $rules['kgb_no_sk'] = ['nullable', 'string', 'max:255'];
            $rules['kgb_tanggal_sk'] = ['nullable', 'date'];
            $rules['kgb_tmt_kgb'] = ['nullable', 'date'];
            $rules['file_sk_kgb'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

            // Pengangkatan (Appointment)
            $rules['pengangkatan_jenis_pengangkatan'] = ['nullable', 'string', 'max:100'];
            $rules['pengangkatan_tmt_pengangkatan'] = ['nullable', 'date'];
            $rules['pengangkatan_no_sk'] = ['nullable', 'string', 'max:255'];
            $rules['pengangkatan_tanggal_sk'] = ['nullable', 'date'];
            $rules['file_sk_pengangkatan'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

            // Override foto khusus web (file upload)
            $rules['foto'] = ['nullable', 'image', 'max:10240', 'mimes:jpg,jpeg,png'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return EmployeeValidationRules::attributes();
    }
}
