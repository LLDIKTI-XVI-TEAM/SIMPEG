<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Menjaga batas otorisasi saat pegawai dinonaktifkan tanpa menerima payload bisnis.
 */
class DeactivateEmployeeRequest extends FormRequest
{
    /**
     * Hanya pengelola pegawai yang memiliki permission eksplisit boleh menonaktifkan data.
     */
    public function authorize(): bool
    {
        // Menjaga kontrak bypass API lokal yang sudah dipakai route pegawai. Flag ini hanya
        // berlaku di environment local sehingga gate role/permission produksi tetap utuh.
        if (app()->environment('local')
            && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true)
            && $user->hasPermission('employees.deactivate');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
