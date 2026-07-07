<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi tindakan menyetujui pengajuan cuti.
 * Otorisasi di sini hanya memastikan akun tertaut ke pegawai.
 * Approver dinamis bisa pegawai biasa; kelayakan per-step ditegakkan person-based di LeaveApprovalService.
 */
class ApproveLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Permission cuti.approve tidak dipakai sebagai syarat karena approver snapshot bisa pegawai biasa.
        return $user?->employee_id !== null;
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
