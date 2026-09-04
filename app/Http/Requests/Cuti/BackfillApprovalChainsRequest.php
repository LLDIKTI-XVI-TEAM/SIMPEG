<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi backfill chain approval cuti dari konfigurasi legacy.
 * Operasi ini mass-update konfigurasi pegawai, jadi harus punya alasan audit dan permission cuti.configure.
 */
class BackfillApprovalChainsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null && $actor->hasPermission('cuti.configure');
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
