<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi pencarian kandidat Kepala Bagian tanpa memperluas data yang dikirim ke browser.
 */
class SupervisorLookupRequest extends FormRequest
{
    /**
     * Menegakkan hak mutasi pegawai pada endpoint lookup, terlepas dari kontrol di Blade.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true)
            && $user->hasPermission('employees.update');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100', 'regex:/[\pL\pN]/u'],
        ];
    }

    /**
     * Mencegah spasi tepi digunakan untuk melewati batas minimum query autocomplete.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }
}
