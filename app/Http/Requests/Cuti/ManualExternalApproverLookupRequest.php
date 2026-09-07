<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

final class ManualExternalApproverLookupRequest extends FormRequest
{
    /** Lookup hanya membuka referensi identitas bagi pemegang permission, bukan akses profil kandidat. */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('cuti.manual.manage') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['bail', 'required', 'string', 'min:2', 'max:100', 'regex:/[\pL\pN]/u'],
        ];
    }

    /** Spasi Unicode dinormalisasi sebelum batas karakter agar query kosong tidak dapat melewati validasi. */
    protected function prepareForValidation(): void
    {
        $query = $this->input('q');

        if (is_string($query)) {
            $this->merge([
                'q' => trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $query)),
            ]);
        }
    }
}
