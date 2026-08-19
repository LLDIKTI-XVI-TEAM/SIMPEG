<?php

namespace App\Http\Requests\Referensi;

use Illuminate\Foundation\Http\FormRequest;

class DeleteProgramStudiRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Program Studi adalah data referensi Data Master; hanya Super Admin yang juga
        // memiliki permission reference_tables.manage boleh mengelolanya (lapisan kedua
        // di atas role middleware pada route), fail-closed terhadap pencabutan permission.
        return $this->user()?->role === 'super_admin'
            && $this->user()?->hasPermission('reference_tables.manage');
    }

    public function rules(): array
    {
        return [];
    }
}
