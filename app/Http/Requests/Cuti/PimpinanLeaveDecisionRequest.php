<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PimpinanLeaveDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->employee_id !== null;
    }

    public function rules(): array
    {
        return [
            'keputusan' => ['required', Rule::in(['DISETUJUI', 'PERUBAHAN', 'DITANGGUHKAN', 'TIDAK_DISETUJUI'])],
            'catatan' => [Rule::requiredIf($this->input('keputusan') !== 'DISETUJUI'), 'nullable', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'catatan.required' => 'Catatan keputusan wajib diisi.',
            'catatan.min' => 'Catatan keputusan minimal berisi 5 karakter.',
        ];
    }
}
