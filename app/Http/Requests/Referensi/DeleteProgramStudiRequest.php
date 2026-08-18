<?php

namespace App\Http\Requests\Referensi;

use Illuminate\Foundation\Http\FormRequest;

class DeleteProgramStudiRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permission menjadi sumber otorisasi agar backend tetap fail-closed saat role berubah.
        return (bool) $this->user()?->hasPermission('reference_tables.manage');
    }

    public function rules(): array
    {
        return [];
    }
}
