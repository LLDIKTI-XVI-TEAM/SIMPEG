<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Memvalidasi keputusan Admin tanpa menerima ulang alasan sensitif milik pemohon. */
final class DecideLeaveCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('cuti.cancellation.manage');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([
                'DISETUJUI',
                'DITOLAK',
            ])],
        ];
    }
}
