<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mutasi data keluarga hanya untuk pengelola data kepegawaian.
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        return [
            'nama_anggota' => ['required', 'string', 'max:255'],
            'hubungan' => ['required', Rule::in(['Suami', 'Istri', 'Anak'])],
            'nik' => ['nullable', 'string', 'digits:16'],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'tanggal_lahir' => ['required', 'date', 'before_or_equal:today'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'status_tunjangan' => ['required', 'boolean'],
            'pekerjaan' => ['nullable', 'string', 'max:100'],
        ];
    }
}
