<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi tindakan menunda pengajuan cuti.
 * Otorisasi di sini bersifat gerbang kasar (pengguna memegang salah satu hak approve cuti);
 * kelayakan approver per-tahap ditegakkan secara person-based di LeaveApprovalService.
 */
class PostponeLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Penundaan hanya boleh dilakukan oleh approver; pemegang salah satu hak approve cuti boleh mencoba.
        return (bool) $user?->hasPermission('cuti.approve_stage1')
            || (bool) $user?->hasPermission('cuti.approve_stage2')
            || (bool) $user?->hasPermission('cuti.approve_stage3');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Alasan wajib saat menunda agar pemohon memahami dasar penundaan dan dapat menindaklanjuti.
            'komentar' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'komentar.required' => 'Alasan penundaan wajib diisi.',
            'komentar.min' => 'Alasan penundaan minimal berisi 5 karakter.',
        ];
    }

    public function attributes(): array
    {
        return [
            'komentar' => 'alasan penundaan',
        ];
    }
}
