<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Support\EmployeeValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
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
            $rules['jenis_pengangkatan'] = ['required', 'string', 'max:100'];
            $rules['tmt'] = ['required', 'date'];
            $rules['nomor_sk'] = ['required', 'string', 'max:255'];
            $rules['tanggal_sk'] = ['required', 'date'];
            $rules['file_sk'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];
            
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
