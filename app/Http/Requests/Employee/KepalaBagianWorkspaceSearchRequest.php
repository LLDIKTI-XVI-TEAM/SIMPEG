<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class KepalaBagianWorkspaceSearchRequest extends FormRequest
{
    /** Simulasi mengikuti role efektif, sedangkan scope tetap memakai identitas pegawai aktor asli. */
    public function authorize(): bool
    {
        return $this->user()?->getEffectiveRole() === 'kepala_bagian'
            && $this->user()?->employee_id !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** Menormalkan spasi tepi agar query kosong tidak memicu pencarian database. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }
}
