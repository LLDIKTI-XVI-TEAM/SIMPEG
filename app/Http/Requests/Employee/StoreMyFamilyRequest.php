<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMyFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Bypass otorisasi di environment lokal saat flag disable auth aktif.
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        // Hanya role pegawai yang dapat mengakses endpoint self-service ini,
        // dan akun tersebut harus sudah dipetakan ke data pegawai.
        return $user !== null
            && $user->role === 'pegawai'
            && $user->employee_id !== null;
    }

    public function rules(): array
    {
        return [
            'nama_anggota' => ['required', 'string', 'max:255'],
            'hubungan' => ['required', Rule::in(['Suami', 'Istri', 'Anak', 'Saudara'])],
            'nik' => ['nullable', 'string', 'digits:16'],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'tanggal_lahir' => ['required', 'date', 'before_or_equal:today'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'status_tunjangan' => ['required', 'boolean'],
            'pekerjaan' => ['nullable', 'string', 'max:100'],
        ];
    }
}
