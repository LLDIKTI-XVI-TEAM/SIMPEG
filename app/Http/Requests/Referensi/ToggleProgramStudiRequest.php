<?php

namespace App\Http\Requests\Referensi;

use Illuminate\Foundation\Http\FormRequest;

class ToggleProgramStudiRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Program Studi adalah data referensi Data Master; hanya super_admin yang boleh
        // mengelolanya (lapisan kedua di atas role middleware pada route), konsisten dengan
        // referensi lain yang memakai role langsung, bukan permission database.
        return $this->user()?->role === 'super_admin';
    }

    public function rules(): array
    {
        return [];
    }
}
