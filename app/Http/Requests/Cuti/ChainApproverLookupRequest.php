<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

class ChainApproverLookupRequest extends FormRequest
{
    /** Kandidat approver hanya referensi identitas, dengan capability konfigurasi yang dapat didelegasikan. */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('cuti.configure') ?? false;
    }

    public function rules(): array
    {
        return ['q' => ['bail', 'required', 'string', 'min:2', 'max:100']];
    }

    /** Menolak query spasi meskipun menggunakan pemisah Unicode. Wildcard dicari secara literal oleh Action. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $this->input('q'))]);
        }
    }
}
