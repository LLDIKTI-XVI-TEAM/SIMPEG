<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use App\Services\Employees\EmployeeDashboardScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEmployeeArchiveOptionsRequest extends FormRequest
{
    /** Lookup form edit tetap memerlukan hak baca berkas dan scope identitas asli. */
    public function authorize(): bool
    {
        $actor = $this->user();
        $employee = $this->route('employee');

        return $actor !== null
            && $actor->hasPermission('employees.update')
            && $actor->hasPermission('dokumen_sk.read')
            && $employee instanceof Employee
            && app(EmployeeDashboardScopeService::class)->forIdentity($actor)->whereKey($employee->id)->exists();
    }

    /** Kategori dibatasi pada empat pilihan SK yang memang dipakai form edit. */
    public function rules(): array
    {
        return [
            'kategori' => ['required', 'string', Rule::in(['sk_pangkat', 'sk_jabatan', 'sk_kgb', 'sk_pengangkatan'])],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['kategori' => 'kategori arsip', 'q' => 'pencarian arsip', 'page' => 'halaman'];
    }
}
