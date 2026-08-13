<?php

namespace App\Http\Requests\History;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEducationHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Bypass otorisasi di environment lokal saat flag disable auth aktif.
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        // Mutasi riwayat pendidikan hanya boleh dilakukan pengelola data kepegawaian.
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        return [
            'jenjang_id' => ['required', 'uuid', 'exists:ref_jenjang_pendidikan,id'],
            'nama_institusi' => ['required', 'string', 'max:255'],
            'program_studi_id' => ['nullable', 'uuid', Rule::exists('ref_program_studi', 'id')->where('is_active', true)],
            'tahun_lulus' => ['required', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'no_ijazah' => ['nullable', 'string', 'max:100'],
        ];
    }
}
