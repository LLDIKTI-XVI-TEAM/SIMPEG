<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class StoreMyEducationHistoryRequest extends FormRequest
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
            'jenjang_id' => ['required', 'uuid', 'exists:ref_jenjang_pendidikan,id'],
            'nama_institusi' => ['required', 'string', 'max:255'],
            'jurusan' => ['nullable', 'string', 'max:255'],
            'tahun_lulus' => ['required', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'no_ijazah' => ['nullable', 'string', 'max:100'],
        ];
    }
}
