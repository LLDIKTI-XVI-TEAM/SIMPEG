<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi backfill chain approval cuti dari konfigurasi legacy.
 * Operasi ini mass-update konfigurasi pegawai dan hanya dapat dijalankan pemilik permission khusus chain.
 */
class BackfillApprovalChainsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('cuti.configure_chain');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'backfill_reason' => ['nullable', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'backfill_reason.min' => 'Alasan backfill minimal berisi 5 karakter.',
            'backfill_reason.max' => 'Alasan backfill maksimal 500 karakter.',
        ];
    }
}
