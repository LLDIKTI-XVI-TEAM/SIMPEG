<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

class RecordDutyPostponementRequest extends FormRequest
{
    /** Memisahkan error aksi terminal dari form cuti lain pada halaman detail yang sama. */
    protected $errorBag = 'dutyPostponement';

    public function authorize(): bool
    {
        // Otorisasi snapshot diperiksa ulang di bawah lock Action agar keputusan tidak memakai approver yang sudah berubah.
        return $this->user()?->employee_id !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'active_step_id' => ['required', 'uuid'],
            'revision_version' => ['required', 'integer', 'min:1'],
            'alasan' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'alasan.required' => 'Alasan tugas dinas mendesak wajib diisi.',
            'alasan.min' => 'Alasan tugas dinas minimal berisi 5 karakter.',
            'alasan.max' => 'Alasan tugas dinas maksimal berisi 500 karakter.',
        ];
    }
}
