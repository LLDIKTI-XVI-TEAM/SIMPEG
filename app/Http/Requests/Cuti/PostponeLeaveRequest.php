<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi tindakan menunda pengajuan cuti.
 * Otorisasi di sini bersifat gerbang kasar (pengguna memegang hak approve cuti);
 * kelayakan approver per-tahap ditegakkan secara person-based di LeaveApprovalService.
 */
class PostponeLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Kelayakan approver per-step tetap ditegakkan di service berdasarkan snapshot aktif.
        // Approver snapshot bisa pegawai biasa; service tetap memverifikasi kecocokan orang pada step aktif.
        return $user?->employee_id !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'active_step_id' => ['required', 'uuid'],
            'revision_version' => ['required', 'integer', 'min:1'],
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
            'active_step_id' => 'tahap persetujuan',
            'revision_version' => 'versi pengajuan',
            'komentar' => 'alasan penundaan',
        ];
    }
}
