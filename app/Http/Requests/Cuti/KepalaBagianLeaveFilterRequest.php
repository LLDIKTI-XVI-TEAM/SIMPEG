<?php

namespace App\Http\Requests\Cuti;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KepalaBagianLeaveFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->getEffectiveRole() === 'kepala_bagian';
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'tahun' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'bulan' => ['nullable', 'integer', 'between:1,12'],
            'jenis_cuti_id' => ['nullable', 'uuid', 'exists:ref_jenis_cuti,id'],
            'status' => ['nullable', 'string', Rule::in([
                'menunggu_approval',
                'disetujui',
                'perlu_perubahan',
                'ditangguhkan',
                LeaveRequest::STATUS_ADMINISTRATIVELY_POSTPONED,
                'ditangguhkan_tugas_dinas',
                LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
                LeaveRequest::STATUS_CANCELLATION_PENDING,
                LeaveRequest::STATUS_CANCELLED,
                'tidak_disetujui',
                'all',
            ])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
        ];
    }
}
