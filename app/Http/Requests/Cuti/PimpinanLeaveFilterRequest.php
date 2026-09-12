<?php

namespace App\Http\Requests\Cuti;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PimpinanLeaveFilterRequest extends FormRequest
{
    /** Permission monitoring tetap diperlukan meskipun aktor memakai halaman berlabel peran. */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('cuti.read_all') === true;
    }

    /** Monitoring tanpa filter tidak otomatis menjadi antrean assignment aktor. */
    protected function prepareForValidation(): void
    {
        if (! $this->has('status')) {
            $this->merge([
                'status' => 'all',
            ]);
        }
    }

    /** Membatasi filter serta ukuran halaman sebelum query monitoring dijalankan. */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'unit_kerja_id' => ['nullable', 'uuid'],
            'periode' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'jenis_cuti_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'status' => ['nullable', Rule::in([
                'all',
                'menunggu_saya',
                'menunggu',
                'disetujui',
                'perubahan',
                'ditangguhkan',
                LeaveRequest::STATUS_ADMINISTRATIVELY_POSTPONED,
                'ditangguhkan_tugas_dinas',
                LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
                LeaveRequest::STATUS_CANCELLATION_PENDING,
                LeaveRequest::STATUS_CANCELLED,
                'tidak_disetujui',
            ])],
        ];
    }
}
