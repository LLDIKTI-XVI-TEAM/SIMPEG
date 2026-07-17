<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeLookupRequest extends FormRequest
{
    /**
     * Lookup hanya boleh digunakan oleh role yang dapat membuka rekap atau konfigurasi Cuti.
     */
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'admin_kepegawaian', 'pimpinan'], true);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100', 'regex:/[\pL\pN]/u'],
        ];
    }

    /**
     * Spasi tepi tidak boleh dipakai untuk melewati batas minimum pencarian.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }
}
