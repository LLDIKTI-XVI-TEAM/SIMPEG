<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi tindakan menyetujui pengajuan cuti.
 * Otorisasi di sini bersifat gerbang kasar (pengguna memegang hak approve cuti);
 * otorisasi inti yang menentukan apakah pengguna adalah approver tahap yang menunggu
 * ditegakkan secara person-based di LeaveApprovalService.
 */
class ApproveLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Kelayakan per-step tetap diuji di service berdasarkan snapshot aktif.
        return (bool) $user?->hasPermission('cuti.approve');
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
