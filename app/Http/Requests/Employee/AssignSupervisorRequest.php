<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Memvalidasi penetapan Kepala Bagian beserta tanggal efektifnya.
 */
class AssignSupervisorRequest extends FormRequest
{
    /**
     * Membatasi mutasi pada dua role pengelola yang juga memiliki permission perubahan pegawai.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true)
            && $user->hasPermission('employees.update');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'kepala_bagian_id' => ['nullable', 'uuid', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'supervisor_id' => ['nullable', 'uuid', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            // Halaman asal non-default harus berasal dari whitelist agar redirect tidak bisa diarahkan ke URL bebas.
            'redirect_to' => ['nullable', 'string', 'in:cuti-config'],
        ];
    }
}
