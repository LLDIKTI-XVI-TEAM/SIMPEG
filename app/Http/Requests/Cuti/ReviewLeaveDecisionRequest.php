<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi keputusan approver yang wajib menyertakan catatan untuk pemohon.
 * Kelayakan approver per-step tetap ditegakkan di LeaveApprovalService berdasarkan snapshot aktif.
 */
class ReviewLeaveDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user?->hasPermission('cuti.approve');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'komentar' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'komentar.required' => 'Catatan keputusan wajib diisi.',
            'komentar.min' => 'Catatan keputusan minimal berisi 5 karakter.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'komentar' => 'catatan keputusan',
        ];
    }
}
