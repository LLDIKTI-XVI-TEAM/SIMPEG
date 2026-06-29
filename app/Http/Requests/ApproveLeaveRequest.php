<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi tindakan menyetujui pengajuan cuti.
 * Otorisasi di sini bersifat gerbang kasar (pengguna memegang salah satu hak approve cuti);
 * otorisasi inti yang menentukan apakah pengguna adalah approver tahap yang menunggu
 * ditegakkan secara person-based di LeaveApprovalService.
 */
class ApproveLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Pemegang salah satu hak approve cuti boleh mencoba; kelayakan per-tahap diuji di service.
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
            // Komentar opsional saat menyetujui; bila diisi disimpan sebagai catatan pada timeline approval.
            'komentar' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'komentar' => 'komentar',
        ];
    }
}
